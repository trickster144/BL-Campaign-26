import express, { RequestHandler } from 'express';
import {
  getArmies,
  createArmyHandler,
  addUnitsHandler,
  moveArmyHandler,
  getBattles,
  getBattleDetail,
} from '../controllers/combatController.js';
import { authenticateToken } from '../middleware/auth.js';
import { requireTeam } from '../middleware/requireTeam.js';

const router = express.Router();

router.use(authenticateToken as RequestHandler);
router.use(requireTeam);

// GET /api/combat/armies
router.get('/armies', getArmies);

// POST /api/combat/armies
router.post('/armies', createArmyHandler);

// POST /api/combat/armies/:armyId/units
router.post('/armies/:armyId/units', addUnitsHandler);

// POST /api/combat/armies/:armyId/move
router.post('/armies/:armyId/move', moveArmyHandler);

// GET /api/combat/battles
router.get('/battles', getBattles);

// GET /api/combat/battles/:battleId
router.get('/battles/:battleId', getBattleDetail);

export default router;
