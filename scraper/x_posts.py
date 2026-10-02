#!/usr/bin/env python3
"""Fetch recent posts from X profiles via twscrape.

Reads a JSON request from stdin:

    {"accounts_db": "...", "limit": 60,
     "targets": [{"username": "theo", "since": "2026-10-01T00:00:00+00:00" | null}]}

Writes a JSON response to stdout:

    {"posts": [...], "errors": [{"username": "..." | null, "message": "..."}]}

`since` is an exclusive stop condition: scanning a profile ends at the first
post published at or before it. Replies and retweets are returned flagged so
the caller can filter them.
"""

import asyncio
import json
import os
import sys
from datetime import datetime, timezone

from twscrape import API, NoAccountError

NO_ACCOUNT_MESSAGE = (
    "No twscrape account configured. Run `php artisan scraper:import-cookies` "
    "after logging in with the twitter-mcp do_login script."
)


def parse_since(value):
    if not value:
        return None

    moment = datetime.fromisoformat(value.replace("Z", "+00:00"))

    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=timezone.utc)

    return moment.astimezone(timezone.utc)


def serialize(tweet):
    published = tweet.date if tweet.date.tzinfo else tweet.date.replace(tzinfo=timezone.utc)

    return {
        "external_id": tweet.id_str or str(tweet.id),
        "url": tweet.url,
        "text": tweet.rawContent,
        "published_at": published.astimezone(timezone.utc).isoformat(),
        "author": tweet.user.displayname,
        "username": tweet.user.username,
        "is_reply": tweet.inReplyToTweetId is not None,
        "is_retweet": tweet.retweetedTweet is not None,
    }


async def fetch_target(api, target, limit):
    username = target["username"]
    since = parse_since(target.get("since"))

    user = await api.user_by_login(username)

    if user is None:
        return [], {"username": username, "message": f"profile @{username} was not found on X"}

    posts = []

    async for tweet in api.user_tweets(user.id, limit=limit):
        published = tweet.date if tweet.date.tzinfo else tweet.date.replace(tzinfo=timezone.utc)

        if since is not None and published <= since:
            break

        posts.append(serialize(tweet))

    return posts, None


async def run(payload):
    api = API(payload["accounts_db"], raise_when_no_account=True)
    limit = int(payload.get("limit", 60))

    posts, errors = [], []

    for target in payload.get("targets", []):
        try:
            target_posts, error = await fetch_target(api, target, limit)
            posts.extend(target_posts)

            if error is not None:
                errors.append(error)
        except NoAccountError:
            errors.append({"username": target.get("username"), "message": NO_ACCOUNT_MESSAGE})
        except Exception as exc:  # reported per profile to the caller
            errors.append({"username": target.get("username"), "message": str(exc)})

    return {"posts": posts, "errors": errors}


def no_account_response():
    return {
        "posts": [],
        "errors": [{"username": None, "message": NO_ACCOUNT_MESSAGE}],
    }


def main():
    payload = json.load(sys.stdin)

    directory = os.path.dirname(payload.get("accounts_db") or "")
    if directory:
        os.makedirs(directory, exist_ok=True)

    try:
        response = asyncio.run(run(payload))
    except NoAccountError:
        json.dump(no_account_response(), sys.stdout, ensure_ascii=False)
        return 1
    except Exception as exc:
        json.dump(
            {"posts": [], "errors": [{"username": None, "message": str(exc)}]},
            sys.stdout,
            ensure_ascii=False,
        )
        return 1

    json.dump(response, sys.stdout, ensure_ascii=False)

    return 0


if __name__ == "__main__":
    sys.exit(main())
