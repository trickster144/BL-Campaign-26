# Steam Authentication Setup Guide

## Step 1: Get Steam Web API Key

1. **Visit Steam Web API Key page**: https://steamcommunity.com/dev/apikey
2. **Log in** with your Steam account (must be the admin account)
3. **Enter Domain Name**: Use your actual domain (e.g., `yourdomain.com`) or `localhost` for testing
4. **Agree to terms** and click **Register**
5. **Copy your API Key** - it will look like: `XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX`

## Step 2: Configure Backend Environment

1. **Edit backend/.env** file
2. **Replace** `CONFIGURE_YOUR_STEAM_API_KEY_HERE` with your actual Steam API key:
   ```
   STEAM_API_KEY=YOUR_ACTUAL_32_CHARACTER_API_KEY_HERE
   ```

3. **Update URLs** to match your deployment:
   ```
   STEAM_RETURN_URL=http://your-domain.com:5001/api/auth/steam/callback
   STEAM_REALM=http://your-domain.com:5001/
   FRONTEND_URL=http://your-domain.com:8080
   ```
   
   For local testing, use:
   ```
   STEAM_RETURN_URL=http://localhost:5001/api/auth/steam/callback
   STEAM_REALM=http://localhost:5001/
   FRONTEND_URL=http://localhost:8080
   ```

## Step 3: Production Security

1. **Generate secure JWT secret**:
   ```bash
   # Option 1: Using openssl
   openssl rand -base64 64
   
   # Option 2: Using Node.js
   node -e "console.log(require('crypto').randomBytes(64).toString('base64'))"
   ```

2. **Update JWT_SECRET** in backend/.env with the generated value

## Step 4: User Management

### User Roles
- `gamemaster` - Full admin access, sees everything
- `blue_admin` / `red_admin` - Team administrators, can approve users
- `blue_member` / `red_member` - Players, can control gameplay
- `blue_observer` / `red_observer` - Read-only access to their team

### Adding Users to Teams
1. Users first log in via Steam (creates account with no team/role)
2. Team admins can then assign roles via the admin interface
3. Gamemaster can assign admin roles and manage teams

## Troubleshooting

### Common Issues
- **"Invalid Steam API Key"**: Make sure your key is exactly 32 characters
- **"Steam login not working"**: Check STEAM_RETURN_URL matches your actual deployment URL
- **"Cannot access after login"**: User may need team assignment by an admin

### Testing Login
1. Start your application: `docker-compose up`
2. Visit: `http://localhost:8080`
3. Click "Sign in with Steam"
4. Should redirect to Steam, then back to your app with logged-in status

### Domain Configuration
- For production, update all localhost references to your actual domain
- SSL/HTTPS is recommended for production (Steam supports both HTTP and HTTPS)
- Make sure firewall allows traffic on ports 5001 (backend) and 8080 (frontend)

## Security Notes

1. **Never commit your Steam API key to version control**
2. **Use strong, unique JWT secrets in production**
3. **Consider using Docker secrets for sensitive values**
4. **Enable SSL/TLS for production deployments**
5. **Regularly rotate JWT secrets and API keys**