#!/usr/bin/env python3
"""Read and decrypt X auth cookies from a Chromium-family browser profile.

JSON stdin:

    {"browser": "brave" | "chrome" | "chromium" | "<profile dir>",
     "names": ["auth_token", "ct0"]}

JSON stdout on success:

    {"cookies": {"auth_token": "...", "ct0": "..."}, "browser": "brave", "profile": "..."}

On failure, {"error": "..."} is written to stdout and the exit code is 1.

Linux decryption scheme (Chromium os_crypt, store version >= 24):
key = PBKDF2-HMAC-SHA1(keyring password, "saltysalt", 1 iteration, 16 bytes),
value = AES-128-CBC(IV = 16 spaces, PKCS#7) of SHA256(host_key) + cookie value,
behind a "v10"/"v11" prefix. The keyring password comes from libsecret
(schema chrome_libsecret_os_crypt_password_v2, application = browser name);
"peanuts" is the fallback used by Chromium when no keyring is available.

Run `read_browser_cookies.py --self-test` to verify the crypto round-trip.
"""

import glob
import hashlib
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives.padding import PKCS7

SALT = b"saltysalt"
IV = b" " * 16
KEYRING_SCHEMA = "chrome_libsecret_os_crypt_password_v2"
STORE_VERSION_WITH_DIGEST = 24

BROWSER_ROOTS = {
    "brave": [
        "~/.config/BraveSoftware/Brave-Browser",
        "~/.var/app/com.brave.Browser/config/BraveSoftware/Brave-Browser",
    ],
    "chrome": [
        "~/.config/google-chrome",
        "~/.var/app/com.google.Chrome/config/google-chrome",
    ],
    "chromium": [
        "~/.config/chromium",
        "~/.var/app/org.chromium.Chromium/config/chromium",
    ],
}

KEYRING_APPLICATIONS = {
    "brave": "brave",
    "chrome": "chrome",
    "chromium": "chromium",
}


def candidate_profiles(browser):
    """Return Cookies database paths for the browser, most recently used first."""
    roots = BROWSER_ROOTS.get(browser, [browser])
    profiles = []

    for root in roots:
        profiles.extend(glob.glob(os.path.join(os.path.expanduser(root), "*", "Cookies")))

    return sorted(profiles, key=os.path.getmtime, reverse=True)


def keyring_password(application):
    try:
        result = subprocess.run(
            ["secret-tool", "lookup", "xdg:schema", KEYRING_SCHEMA, "application", application],
            capture_output=True,
            text=True,
            timeout=10,
        )
    except (OSError, subprocess.SubprocessError):
        return None

    return result.stdout.strip() or None


def derive_key(password):
    return hashlib.pbkdf2_hmac("sha1", password, SALT, 1, 16)


def decrypt_value(blob, key, host_key, store_version):
    """Decrypt one encrypted_value blob, or return None when it is unreadable."""
    if len(blob) < 3 or blob[:3] not in (b"v10", b"v11"):
        return None

    decryptor = Cipher(algorithms.AES(key), modes.CBC(IV)).decryptor()

    try:
        padded = decryptor.update(blob[3:]) + decryptor.finalize()
        unpadder = PKCS7(128).unpadder()
        plaintext = unpadder.update(padded) + unpadder.finalize()
    except ValueError:
        return None

    digest = hashlib.sha256(host_key.encode()).digest()
    if store_version >= STORE_VERSION_WITH_DIGEST and plaintext[:32] == digest:
        plaintext = plaintext[32:]

    try:
        return plaintext.decode("utf-8")
    except UnicodeDecodeError:
        return None


def read_cookies(profile, names):
    """Copy the locked database aside and return (store_version, cookie rows)."""
    with tempfile.TemporaryDirectory() as tmp:
        copy = os.path.join(tmp, "Cookies")
        shutil.copy(profile, copy)

        db = sqlite3.connect(copy)
        try:
            row = db.execute("select value from meta where key = 'version'").fetchone()
            store_version = int(row[0]) if row else 0

            placeholders = ",".join("?" * len(names))
            rows = db.execute(
                f"select name, host_key, encrypted_value from cookies "
                f"where name in ({placeholders}) "
                f"and (host_key like '%x.com%' or host_key like '%twitter.com%')",
                names,
            ).fetchall()
        finally:
            db.close()

        return store_version, rows


def extract(browser, names):
    application = KEYRING_APPLICATIONS.get(browser, browser)
    password = keyring_password(application) or "peanuts"

    profiles = candidate_profiles(browser)
    if not profiles:
        return None, f"no Cookies database found for browser '{browser}'"

    for profile in profiles:
        try:
            store_version, rows = read_cookies(profile, names)
        except sqlite3.Error:
            continue

        key = derive_key(password.encode())
        cookies = {}

        for name, host_key, blob in rows:
            if not blob:
                continue

            value = decrypt_value(blob, key, host_key, store_version)
            if value:
                cookies[name] = value

        if all(name in cookies for name in names):
            return {"cookies": cookies, "browser": browser, "profile": os.path.dirname(profile)}, None

    return None, f"no decryptable cookies {names} found for browser '{browser}'"


def self_test():
    key = derive_key(b"test-password")
    host = ".x.com"

    for store_version in (23, 24):
        digest = hashlib.sha256(host.encode()).digest() if store_version >= STORE_VERSION_WITH_DIGEST else b""
        plaintext = digest + b"cookie-value-123"

        padder = PKCS7(128).padder()
        padded = padder.update(plaintext) + padder.finalize()
        encryptor = Cipher(algorithms.AES(key), modes.CBC(IV)).encryptor()
        blob = b"v11" + encryptor.update(padded) + encryptor.finalize()

        assert decrypt_value(blob, key, host, store_version) == "cookie-value-123", f"round-trip failed for v{store_version}"

    assert decrypt_value(b"v11" + b"\x00" * 32, key, host, 24) is None, "garbage blob must not decrypt"
    assert decrypt_value(b"v12" + b"\x00" * 16, key, host, 24) is None, "unknown prefix must not decrypt"

    print("self-test ok")
    return 0


def main():
    if "--self-test" in sys.argv:
        return self_test()

    payload = json.load(sys.stdin)
    browser = payload.get("browser", "brave")
    names = payload.get("names", ["auth_token", "ct0"])

    result, error = extract(browser, names)

    if error is not None:
        json.dump({"error": error}, sys.stdout)
        return 1

    json.dump(result, sys.stdout)
    return 0


if __name__ == "__main__":
    sys.exit(main())
