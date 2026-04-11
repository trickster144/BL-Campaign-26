import express, { RequestHandler } from 'express';
import { getResources, getLocationResourcesHandler, getProductionChains } from '../controllers/resourceController.js';
import { authenticateToken } from '../middleware/auth.js';

const router = express.Router();

// GET /api/resources - List all resource types
router.get('/', authenticateToken as RequestHandler, getResources);

// GET /api/resources/location/:locationId - Resources at a location
router.get('/location/:locationId', authenticateToken as RequestHandler, getLocationResourcesHandler);

// GET /api/resources/production-chains - All production recipes
router.get('/production-chains', authenticateToken as RequestHandler, getProductionChains);

export default router;
