# PHP + Apache (Debian) — Hardened Docker Compose

Production-ready Docker setup for PHP + Apache on Debian.

### Debian Trixie (apt-only)
Everything (PHP, Imagick, ImageMagick, FFmpeg) is installed from Debian’s APT repos.  
**Pros:** single update channel (`apt`), Debian hardening, lowest maintenance.  

- `docker-compose.trixie-debian.yml`  
  - Debian 13  
  - PHP **8.4**  
  - ImageMagick **7**  
  - `php-imagick` **3.8.x`  
  - PDO (`pdo_mysql`, `pdo_sqlite`)  
  - Imagick with **HEIC/AVIF** support  
  - `ffmpeg` with **HEVC/H.265**  
  - PHPMailer bundled (vendor tree)  
  - ZIP extension  
  - **OPcache disabled by default**  
  - Runs as **non-root** and supports **read-only** root filesystem  


#### Alternatives builds

- **Debian Bookworm (apt-only):** Debian 12 • PHP **8.2** • ImageMagick **6** • `php-imagick` **3.7.x`  
- **Debian Trixie + Upstream PHP/PECL:** Debian 13 • PHP **8.3** (`php:*` image) • ImageMagick **7** • PECL `imagick` **3.8.0`  
- **Debian Bookworm + Upstream PHP/PECL:** Debian 12 • PHP **8.3** (`php:*` image) • ImageMagick **6** • PECL `imagick` **3.8.0`  

Read more about the alternative builds here [Alternative builds](notes/builds.md)

---

## Setup

#### Clone the repository (replace `webserver01` with your project folder name)
```
git clone https://github.com/theoppermann/php-apache-imagick-ffmpeg.git webserver01
cd webserver01
```
#### Make default dirs (See [uploads.md](notes/uploads.md) for legacy /uploads setup.)
```
mkdir -p ./www/cache ./data ./secrets ./www/data ./conf/apache-conf
sudo chown -R 33:33 ./data
sudo chmod -R 750 ./data
```
#### Create the mail secret
```
echo "super-secret-password" > secrets/mail_pass.txt
```
#### Build
```
docker compose -f docker-compose.yml -f docker-compose.trixie-debian.yml build --no-cache --pull
```
#### Alternative builds 
```
docker compose -f docker-compose.yml -f <override> build --no-cache --pull"
```
Replace ``` <override> ```with one of:

- docker-compose.trixie-debian.yml (recommended)

- docker-compose.trixie-im7.yml

- docker-compose.bookworm-php.yml

- docker-compose.bookworm-debian.yml

#### Health check you need to create a health.php file else status return unhealty
```
echo "<?php http_response_code(200); echo 'OK';" > www/health.php
```

#### Run
```
docker compose up -d
```

#### Stop
```
docker compose down
```
#### View logs
```
docker logs -f <the name of the container> or
docker compose -f docker-compose.yml -f <override> logs -f
```

#### Check the container name with:
```
docker ps
```
---

#### Verification to see if everything was build use the included php testfile.
```
cp index.php www/index.php
```
and open ```http://ipaddress``` in a webbrowser remember to add ports if you use someting else than 80 or 443

---

#### Configuration
- **DEV (unsafe) mode** - read [Disable readonly](notes/dev.md)
- **PHP Modifiers** - in the compose file see [Configurations](notes/conf.md)
- **Apache Overrides** – The container has a pre-baked include that loads any `.conf` files you mount into `./conf/apache-conf/`.  
  See [Custom Apache settings](notes/override.md) for details and examples.
- How to add **HTTPS** - see [Traefik](notes/traefik.md) or [Traefik Legacy](notes/traefik_legacy.md)

---

#### Notes

- You migh want to change or remove container_name: webserv01 in the compose file
- Logs: docker logs <the name of the container>
- Exec: docker exec -it <the name of the container> sh
- Root filesystem is read-only
- /tmp is mounted as tmpfs
- Keep secrets and userdata / cache out of Git (add it to .gitignore)


