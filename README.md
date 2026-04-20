# Black Legion Campaign

A PHP/MySQL web application for the Black Legion campaign game.

## Quick Start (Docker)

### Prerequisites
- [Docker](https://www.docker.com/products/docker-desktop/) installed

### 1. Clone the repo
```bash
git clone https://github.com/YOUR_USERNAME/YOUR_REPO.git
cd YOUR_REPO
```

### 2. Configure environment
```bash
cp .env.example .env
# Edit .env with your preferred database credentials
```

### 3. Seed the database
Export your current MySQL database and place the `.sql` dump in `init-db/`:
```bash
mysqldump -h 10.0.0.28 -u ash_user_copilot -p campaign_data > init-db/001-schema.sql
```
This will auto-run when the MySQL container starts for the first time.

### 4. Start the app
```bash
docker compose up -d --build
```

The app will be available at **http://localhost:8080**

### 5. Stop the app
```bash
docker compose down
```

To also delete the database volume:
```bash
docker compose down -v
```

---

## Local Development (XAMPP)

1. Place this folder in your XAMPP `htdocs` directory
2. Create `files/config.local.php` with your database credentials:
   ```php
   <?php
   $db_host = "localhost";
   $db_user = "root";
   $db_pass = "";
   $db_name = "campaign_data";
   ```
3. Access via `http://localhost/your-folder-name/`

---

## Project Structure

| Path | Description |
|------|-------------|
| `index.php` | Home page — world market prices |
| `auth/` | Login, logout, registration |
| `admin/` | Admin tools (trains, vehicles, weapons, tick) |
| `setup/` | Database setup & seeding scripts |
| `files/` | Shared includes (config, header, sidebar, auth) |
| `assets/` | CSS, JS, images, plugins |
