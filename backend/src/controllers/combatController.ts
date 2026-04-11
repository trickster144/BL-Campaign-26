import { Request, Response } from 'express';
import { RowDataPacket } from 'mysql2/promise';
import { query } from '../databaseConfig.js';
import { createArmy, addUnitsToArmy, moveArmy } from '../combatService.js';
import { AuthenticatedRequest } from '../middleware/auth.js';

export const getArmies = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const armies = await query<RowDataPacket[]>(
      `SELECT a.*, l.name AS location_name
       FROM armies a
       LEFT JOIN locations l ON l.id = a.location_id
       WHERE a.team = ?
       ORDER BY a.id`,
      [user.team],
    );

    // Attach units to each army
    for (const army of armies) {
      const units = await query<RowDataPacket[]>(
        'SELECT * FROM army_units WHERE army_id = ?',
        [army.id],
      );
      (army as any).units = units;
    }

    res.json(armies);
  } catch (error) {
    console.error('getArmies error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const createArmyHandler = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const { name, generalId, locationId } = req.body;
    if (!name || !locationId) {
      res.status(400).json({ error: 'name and locationId required' });
      return;
    }

    const armyId = await createArmy(user.team, name, generalId ?? null, locationId);
    res.status(201).json({ armyId });
  } catch (error) {
    console.error('createArmy error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const addUnitsHandler = async (req: Request, res: Response): Promise<void> => {
  try {
    const armyId = parseInt(req.params.armyId, 10);
    const { unitType, count } = req.body;
    if (!unitType || !count) {
      res.status(400).json({ error: 'unitType and count required' });
      return;
    }

    const result = await addUnitsToArmy(armyId, unitType, count);
    if (!result.success) {
      res.status(400).json({ error: result.message });
      return;
    }
    res.json({ message: 'Units added' });
  } catch (error) {
    console.error('addUnits error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const moveArmyHandler = async (req: Request, res: Response): Promise<void> => {
  try {
    const armyId = parseInt(req.params.armyId, 10);
    const { hexQ, hexR } = req.body;
    if (hexQ === undefined || hexR === undefined) {
      res.status(400).json({ error: 'hexQ and hexR required' });
      return;
    }

    const result = await moveArmy(armyId, hexQ, hexR);
    if (!result.success) {
      res.status(400).json({ error: result.message });
      return;
    }
    res.json({ message: 'Army moving' });
  } catch (error) {
    console.error('moveArmy error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const getBattles = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const battles = await query<RowDataPacket[]>(
      `SELECT b.*, l.name AS location_name,
              a1.name AS attacker_name, a1.team AS attacker_team,
              a2.name AS defender_name, a2.team AS defender_team
       FROM battles b
       JOIN locations l ON l.id = b.location_id
       JOIN armies a1 ON a1.id = b.attacker_army_id
       JOIN armies a2 ON a2.id = b.defender_army_id
       WHERE a1.team = ? OR a2.team = ?
       ORDER BY b.start_time DESC
       LIMIT 50`,
      [user.team, user.team],
    );
    res.json(battles);
  } catch (error) {
    console.error('getBattles error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const getBattleDetail = async (req: Request, res: Response): Promise<void> => {
  try {
    const battleId = parseInt(req.params.battleId, 10);
    if (isNaN(battleId)) {
      res.status(400).json({ error: 'Invalid battle ID' });
      return;
    }

    const battles = await query<RowDataPacket[]>(
      `SELECT b.*, l.name AS location_name,
              a1.name AS attacker_name, a1.team AS attacker_team, a1.strength AS attacker_strength,
              a2.name AS defender_name, a2.team AS defender_team, a2.strength AS defender_strength
       FROM battles b
       JOIN locations l ON l.id = b.location_id
       JOIN armies a1 ON a1.id = b.attacker_army_id
       JOIN armies a2 ON a2.id = b.defender_army_id
       WHERE b.id = ?`,
      [battleId],
    );

    if (battles.length === 0) {
      res.status(404).json({ error: 'Battle not found' });
      return;
    }

    res.json(battles[0]);
  } catch (error) {
    console.error('getBattleDetail error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};
