# Containerizing NaijaScores with Docker

This runs a fully containerized copy of the app **alongside** your existing
live site — on a different port, with its own separate database — so
nothing about the live deployment is touched or put at risk while you learn
and test this.

---

## What you're building

Two containers, wired together by Docker Compose:
- **`app`** — PHP 8.3 + Apache, built from this project's `Dockerfile`, with
  the same four background sync jobs running inside it via cron
- **`db`** — MySQL 8, seeded automatically from `sql/schema.sql` the first
  time it starts

Real secrets (DB password, API keys) are supplied only at container
**runtime** via a `.env` file — never baked into the image itself. This
matters: if you ever pushed this image to a public registry, a secret baked
into a layer would be exposed forever, even after you "removed" it in a
later layer. Environment variables at runtime don't have that problem.

## 1. Install Docker on your Oracle server

SSH in as usual, then:
```bash
sudo apt update
sudo apt install -y docker.io docker-compose-v2
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```
That last line lets you run `docker` without `sudo` every time — but it only
takes effect after you **log out and reconnect**:
```bash
exit
```
then SSH back in as normal.

Confirm it worked:
```bash
docker --version
docker compose version
```

## 2. Get the new Docker files onto the server

From your **local Windows machine**, upload the new files (everything else
is already on the server from before):
```powershell
scp -i C:\Users\DELL\Downloads\ssh-key-2026-09-22.key C:\xampp\htdocs\public_html\NaijaScores\Dockerfile ubuntu@140.238.87.98:/tmp/
scp -i C:\Users\DELL\Downloads\ssh-key-2026-09-22.key C:\xampp\htdocs\public_html\NaijaScores\docker-compose.yml ubuntu@140.238.87.98:/tmp/
scp -i C:\Users\DELL\Downloads\ssh-key-2026-09-22.key C:\xampp\htdocs\public_html\NaijaScores\.dockerignore ubuntu@140.238.87.98:/tmp/
scp -i C:\Users\DELL\Downloads\ssh-key-2026-09-22.key C:\xampp\htdocs\public_html\NaijaScores\.env.example ubuntu@140.238.87.98:/tmp/
scp -i C:\Users\DELL\Downloads\ssh-key-2026-09-22.key C:\xampp\htdocs\public_html\NaijaScores\config\config.example.php ubuntu@140.238.87.98:/tmp/
scp -i C:\Users\DELL\Downloads\ssh-key-2026-09-22.key -r C:\xampp\htdocs\public_html\NaijaScores\docker ubuntu@140.238.87.98:/tmp/
```

Back in your **SSH session**, move everything into place (your real,
running site stays at `/var/www/naijascores` — we're building this
container image from a **separate copy** so the two never interfere):
```bash
sudo cp -r /var/www/naijascores /home/ubuntu/naijascores-docker
sudo chown -R ubuntu:ubuntu /home/ubuntu/naijascores-docker
cp /tmp/Dockerfile /tmp/docker-compose.yml /tmp/.dockerignore /tmp/.env.example /home/ubuntu/naijascores-docker/
cp /tmp/config.example.php /home/ubuntu/naijascores-docker/config/
cp -r /tmp/docker /home/ubuntu/naijascores-docker/
cd /home/ubuntu/naijascores-docker
```

## 3. Create your real `.env` file

```bash
cp .env.example .env
nano .env
```
Fill in real values for `DB_PASS`, `MYSQL_ROOT_PASSWORD` (use a different
password than `DB_PASS`), `FOOTBALL_DATA_TOKEN`, `API_FOOTBALL_KEY`, and
`CRON_SECRET`. Save (`Ctrl+O`, Enter, `Ctrl+X`).

## 4. Build and start it

```bash
docker compose up -d --build
```
The first build takes a few minutes (downloading the PHP and MySQL base
images). `-d` runs it in the background so your terminal stays free.

Watch it start up:
```bash
docker compose logs -f
```
(`Ctrl+C` to stop watching — this doesn't stop the containers, just the log
view.)

## 5. Verify it's actually working

Check both containers are running:
```bash
docker compose ps
```
Both `app` and `db` should show `Up` (and `db` should show `healthy` once
its healthcheck passes, usually within 10-30 seconds of startup).

Test from inside the server first (no firewall changes needed for this):
```bash
curl -I http://localhost:8080
```
Should return `HTTP/1.1 200 OK`.

If you want to check it from your actual browser too, you'd need to open
port 8080 the same way you opened 80/443 earlier — both in Oracle's Security
List **and** the host's `iptables` rules (see `ORACLE_DEPLOYMENT.md` and the
firewall debugging you already went through for the pattern). This step is
optional — the `curl` test above already confirms the container itself
works correctly.

## 6. Seed some real data

The database starts empty. Run the syncs the same way as before, just
routed into the container instead of the host directly:
```bash
docker compose exec app php bin/sync_matches.php
docker compose exec app php bin/sync_standings.php
```

Then check the cron jobs are firing automatically inside the container, same
as you verified on the real server:
```bash
docker compose exec app tail -20 /var/www/html/storage/sync.log
```

## 7. Day-to-day commands, for reference

```bash
docker compose stop          # stop both containers, keep everything
docker compose start         # start them again
docker compose down          # stop and remove containers (data in named volumes survives)
docker compose down -v       # stop and WIPE the database volume too — careful
docker compose logs -f app   # follow just the app container's logs
docker compose exec app bash # get a shell inside the running app container
```

---

## What this demonstrates, and what's still manual

**What's now containerized:** the entire app + database + background jobs,
reproducible with one command on any machine that has Docker — no manual
`apt install` of PHP extensions, no hand-typed MySQL setup, no hand-edited
Apache vhost config. That reproducibility is the actual point.

**What's intentionally still outside the container, for now:** Nginx as a
reverse proxy in front of multiple containerized apps (the "20 apps on one
server" scenario from earlier) — that's the natural next step once you're
comfortable with this single-app setup, and a good follow-up project in its
own right.

**Whether to ever migrate the live site onto this** is a separate decision,
not something this guide does automatically — running both side by side
for a while, comparing them, is the safer way to build confidence before
cutting over anything real.
