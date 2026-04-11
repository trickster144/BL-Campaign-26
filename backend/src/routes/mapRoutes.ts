import express, { RequestHandler } from 'express';
import { getMap, getLocations, getLocationDetail } from '../controllers/mapController.js';
import { authenticateToken } from '../middleware/auth.js';

const router = express.Router();

router.use(authenticateToken as RequestHandler);

// GET /api/map - Full hex grid with fog of war
router.get('/', getMap);

// GET /api/map/locations - All visible locations
router.get('/locations', getLocations);

// GET /api/map/locations/:locationId - Single location full info
router.get('/locations/:locationId', getLocationDetail);

export default router;
