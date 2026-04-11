import express, { RequestHandler } from 'express';
import { getGameState, updateGameState } from '../controllers/gameController.js';
import { authenticateToken } from '../middleware/auth.js';

const router = express.Router();

// GET /api/game/state - Get current game state
router.get('/state', authenticateToken as RequestHandler, getGameState);

// PUT /api/game/state - Update game state (gamemaster only)
router.put('/state', authenticateToken as RequestHandler, updateGameState);

export default router;