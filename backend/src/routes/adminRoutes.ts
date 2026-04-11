import express, { RequestHandler } from 'express';
import { getUsers, updateUserRole, approveUser, gamemasterAction } from '../controllers/adminController.js';
import { authenticateToken as RequestHandler, requireRole } from '../middleware/auth.js';

const router = express.Router();

router.use(authenticateToken as RequestHandler as RequestHandler);

// GET /api/admin/users - List all users (admin/gamemaster only)
router.get('/users', requireRole(['admin', 'gamemaster']) as RequestHandler, getUsers);

// PUT /api/admin/users/:userId/role - Change user role
router.put('/users/:userId/role', requireRole(['admin']) as RequestHandler, updateUserRole);

// POST /api/admin/users/:userId/approve - Approve user to a team
router.post('/users/:userId/approve', requireRole(['admin', 'gamemaster']) as RequestHandler, approveUser);

// POST /api/admin/gamemaster - Execute gamemaster override
router.post('/gamemaster', requireRole(['admin', 'gamemaster']) as RequestHandler, gamemasterAction);

export default router;
