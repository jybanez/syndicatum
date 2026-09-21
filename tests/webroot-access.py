#!/usr/bin/env python3
"""Exercise the deployable .htaccess against isolated Apache, never live WAMP."""

from __future__ import annotations

import pathlib
import os
import shutil
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


ROOT = pathlib.Path(__file__).resolve().parents[1]


def write(root: pathlib.Path, name: str, contents: str = "fixture") -> None:
    target = root / name
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(contents, encoding="utf-8")


def docker(*arguments: str) -> str:
    return subprocess.check_output(["docker", *arguments], text=True).strip()


def status(base: str, path: str) -> int:
    try:
        with urllib.request.urlopen(base + path, timeout=5) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="syndicatum-webroot-") as temporary:
        fixture = pathlib.Path(temporary)
        # tempfile creates mode 0700 on Linux; Apache's www-data must traverse
        # the bind-mounted fixture in CI, even though it remains test-only.
        os.chmod(fixture, 0o755)
        shutil.copyfile(ROOT / ".htaccess", fixture / ".htaccess")
        for name in (
            ".git/config", ".env.example", "output/history.sql", "runtime/app.log",
            "logs/request.log", "cache/session.dat", "temp/notes.txt",
            "backup/restore.syndicatum-backup", "coverage/index.html",
            "test-results/report.html", "schema/archive.sql", "private/.secrets/item",
            "tests/smoke.php", "scripts/task.php", "docker/apache.conf",
            "schema/mysql84/baseline.json", "docs/internal.md",
        ):
            write(fixture, name)
        write(fixture, "index.php", "<?php echo 'application';")
        write(fixture, "api/health.php", "<?php echo 'healthy';")
        write(fixture, "assets/app.css", "body { color: black; }")
        write(fixture, ".well-known/verification.txt", "verification")
        write(fixture, ".well-known/oauth-protected-resource.php", "<?php echo 'metadata';")
        write(fixture, "oauth/authorize.php", "<?php echo 'oauth';")

        container = docker(
            "run", "--rm", "--detach", "--publish", "127.0.0.1::80",
            "--volume", f"{fixture}:/var/www/html:ro",
            "--volume", f"{ROOT / 'docker' / 'apache-syndicatum.conf'}:/etc/apache2/conf-enabled/syndicatum.conf:ro",
            "--entrypoint", "sh", "php:8.2-apache-bookworm", "-c",
            "a2enmod rewrite >/dev/null && apache2-foreground",
        )
        try:
            address = docker("port", container, "80/tcp").splitlines()[0]
            base = f"http://{address}"
            for _ in range(40):
                try:
                    if status(base, "/") == 200:
                        break
                except (OSError, TimeoutError):
                    pass
                time.sleep(0.25)
            else:
                raise AssertionError(
                    "Isolated Apache did not become ready:\n" + docker("logs", container)
                )

            checks = [
                ("repository metadata", "/.git/config", {403, 404}),
                ("dotfile", "/.env.example", {403, 404}),
                ("generated output", "/output/history.sql", {403, 404}),
                ("runtime log", "/runtime/app.log", {403, 404}),
                ("logs directory", "/logs/request.log", {403, 404}),
                ("cache directory", "/cache/session.dat", {403, 404}),
                ("temp directory", "/temp/notes.txt", {403, 404}),
                ("backup artifact", "/backup/restore.syndicatum-backup", {403, 404}),
                ("SQL artifact", "/schema/archive.sql", {403, 404}),
                ("verification output", "/coverage/index.html", {403, 404}),
                ("test output", "/test-results/report.html", {403, 404}),
                ("nested dot directory", "/private/.secrets/item", {403, 404}),
                ("test source", "/tests/smoke.php", {403, 404}),
                ("operations script", "/scripts/task.php", {403, 404}),
                ("server configuration", "/docker/apache.conf", {403, 404}),
                ("schema metadata", "/schema/mysql84/baseline.json", {403, 404}),
                ("internal documentation", "/docs/internal.md", {403, 404}),
                ("well-known file", "/.well-known/verification.txt", {200}),
                ("well-known rewrite", "/.well-known/oauth-protected-resource", {200}),
                ("application root", "/", {200}),
                ("API health", "/api/health.php", {200}),
                ("static asset", "/assets/app.css", {200}),
                ("authenticated route", "/users", {200}),
                ("OAuth route", "/oauth/authorize", {200}),
            ]
            for label, path, expected in checks:
                actual = status(base, path)
                print(f"{label}: {path} -> {actual}")
                if actual not in expected:
                    raise AssertionError(f"{label}: expected {sorted(expected)}, received {actual}")
            print("PASS: sensitive paths denied; application paths allowed; no rewrite loop")
        finally:
            subprocess.run(["docker", "rm", "--force", container], check=True, capture_output=True)


if __name__ == "__main__":
    main()
