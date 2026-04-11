import express, { RequestHandler } from 'express';
import { steamLogin, steamCallback, getProfile, logout } from '../controllers/authController.js';
import { authenticateToken } from '../middleware/auth.js';

const router = express.Router();

// GET /api/auth/steam - Redirect to Steam OpenID login
router.get('/steam', steamLogin);

// GET /api/auth/steam/callback - Handle Steam callback
router.get('/steam/callback', steamCallback);

// GET /api/auth/profile - Get current user profile
router.get('/profile', authenticateToken as RequestHandler, getProfile);

// POST /api/auth/logout - Logout
router.post('/logout', authenticateToken as RequestHandler, logout);

export default router;
