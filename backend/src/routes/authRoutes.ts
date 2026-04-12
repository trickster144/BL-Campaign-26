import express, { RequestHandler } from 'express';
import { steamLogin, steamCallback, getProfile, logout } from '../controllers/authController.js';
import { authenticateToken } from '../middleware/auth.js';

const router = express.Router();

// GET /api/auth/steam - Redirect to Steam OpenID login
router.get('/steam', steamLogin);

// GET /api/auth/steam/callback - Handle Steam callback
router.get('/steam/callback', steamCallback);

// GET /api/auth/me - Get current user (used by frontend AuthContext)
router.get('/me', authenticateToken as RequestHandler, getProfile);

// GET /api/auth/profile - Alias kept for backwards compatibility
router.get('/profile', authenticateToken as RequestHandler, getProfile);

// GET /api/auth/verify - Quick token validity check
router.get('/verify', authenticateToken as RequestHandler, (_req, res) => {
  res.json({ valid: true });
});

// POST /api/auth/logout - Logout
router.post('/logout', authenticateToken as RequestHandler, logout);

export default router;
