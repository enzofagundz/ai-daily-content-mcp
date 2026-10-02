#!/usr/bin/env python3
"""Import X auth cookies into the twscrape accounts pool.

Reads a JSON request from stdin:

    {"accounts_db": "...", "username": "default",
     "cookies": "auth_token=...; ct0=..."}

Writes {"accounts": <count>} to stdout.
"""

import asyncio
import json
import os
import sys

from twscrape import API


async def run(payload):
    api = API(payload["accounts_db"])
    await api.pool.add_account_cookies(payload["username"], payload["cookies"])
    accounts = await api.pool.accounts_info()

    return {"accounts": len(accounts)}


def main():
    payload = json.load(sys.stdin)

    directory = os.path.dirname(payload.get("accounts_db") or "")
    if directory:
        os.makedirs(directory, exist_ok=True)

    json.dump(asyncio.run(run(payload)), sys.stdout)

    return 0


if __name__ == "__main__":
    sys.exit(main())
