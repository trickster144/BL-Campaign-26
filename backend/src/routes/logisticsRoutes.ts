import express from 'express';
import {
  getVehicles,
  createVehicleHandler,
  getContainers,
  createRoute,
  startTripHandler,
  getTrips,
  setSchedule,
} from '../controllers/logisticsController.js';
import { authenticateToken } from '../middleware/auth.js';
import { requireTeam } from '../middleware/requireTeam.js';

const router = express.Router();

// All logistics routes require authentication and team membership
router.use(authenticateToken);
router.use(requireTeam);

// GET /api/logistics/vehicles
router.get('/vehicles', getVehicles);

// POST /api/logistics/vehicles
router.post('/vehicles', createVehicleHandler);

// GET /api/logistics/containers
router.get('/containers', getContainers);

// POST /api/logistics/routes
router.post('/routes', createRoute);

// POST /api/logistics/trips
router.post('/trips', startTripHandler);

// GET /api/logistics/trips
router.get('/trips', getTrips);

// POST /api/logistics/schedules
router.post('/schedules', setSchedule);

export default router;
