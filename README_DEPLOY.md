Deployment notes — Portainer / Docker Compose

1) Push changes to GitHub
   - Commit all changes and push to the repo: git add -A && git commit -m "Deployment: compose ports and env files" && git push
   - Or run simple-push-fixes.bat if available.

2) Use stack.env in Portainer
   - In Portainer stack editor either upload stack.env or paste its contents when deploying the compose file.
   - The compose file uses host ports by default: backend -> 10012, frontend -> 10011. Change these in stack.env if needed.

3) Port checks
   - If Portainer reports "port already allocated" stop the process using the port or pick different host ports.
   - Linux: sudo ss -ltnp | grep 10012
   - Windows: netstat -ano | findstr :10012

4) Steam auth
   - Ensure STEAM_API_KEY is set in stack.env. STEAM_RETURN_URL and STEAM_REALM must match your public domain.

5) Debugging
   - Check container logs in Portainer for build or runtime errors.
   - For build failures, look at the service logs during build and the Dockerfile referenced in the service.

6) Local dev
   - Use backend/.env.example as a template for local backend/.env
   - Use stack.env for Portainer deployments
