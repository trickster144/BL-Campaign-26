import { Request, Response } from 'express';
import { RowDataPacket } from 'mysql2/promise';
import { query, execute } from '../databaseConfig.js';
import {
  createVehicle,
  startTrip,
} from '../logisticsService.js';
import { AuthenticatedRequest } from '../middleware/auth.js';

export const getVehicles = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const vehicles = await query<RowDataPacket[]>(
      `SELECT v.*, vt.name AS type_name, vt.category, vt.capacity, vt.max_speed
       FROM vehicles v
       JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
       WHERE v.team = ?
       ORDER BY v.id`,
      [user.team],
    );
    res.json(vehicles);
  } catch (error) {
    console.error('getVehicles error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const createVehicleHandler = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const { vehicleTypeId, locationId, name } = req.body;
    if (!vehicleTypeId || !locationId) {
      res.status(400).json({ error: 'vehicleTypeId and locationId required' });
      return;
    }

    const vehicleId = await createVehicle(user.team, vehicleTypeId, locationId, name);
    res.status(201).json({ vehicleId });
  } catch (error) {
    console.error('createVehicle error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const getContainers = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const containers = await query<RowDataPacket[]>(
      `SELECT c.* FROM containers c
       LEFT JOIN vehicles v ON v.id = c.vehicle_id
       WHERE (v.team = ? OR c.location_id IN (
         SELECT id FROM locations WHERE team = ?
       ))
       ORDER BY c.id`,
      [user.team, user.team],
    );
    res.json(containers);
  } catch (error) {
    console.error('getContainers error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const createRoute = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const { name, startLocationId, endLocationId, distance, transportType } = req.body;
    if (!name || !startLocationId || !endLocationId || !distance || !transportType) {
      res.status(400).json({ error: 'All route fields required' });
      return;
    }

    const result = await execute(
      `INSERT INTO routes (name, start_location_id, end_location_id, distance, transport_type, team)
       VALUES (?, ?, ?, ?, ?, ?)`,
      [name, startLocationId, endLocationId, distance, transportType, user.team],
    );
    res.status(201).json({ routeId: result.insertId });
  } catch (error) {
    console.error('createRoute error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const startTripHandler = async (req: Request, res: Response): Promise<void> => {
  try {
    const { vehicleId, destinationLocationId, cargo } = req.body;
    if (!vehicleId || !destinationLocationId) {
      res.status(400).json({ error: 'vehicleId and destinationLocationId required' });
      return;
    }

    const result = await startTrip(vehicleId, destinationLocationId, cargo);
    if (!result.success) {
      res.status(400).json({ error: result.message });
      return;
    }
    res.json({ message: 'Trip started' });
  } catch (error) {
    console.error('startTrip error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const getTrips = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const trips = await query<RowDataPacket[]>(
      `SELECT v.*, vt.name AS type_name,
              l1.name AS origin_name, l2.name AS destination_name
       FROM vehicles v
       JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
       LEFT JOIN locations l1 ON l1.id = v.location_id
       LEFT JOIN locations l2 ON l2.id = v.destination_location_id
       WHERE v.team = ? AND v.is_moving = TRUE
       ORDER BY v.departure_time DESC`,
      [user.team],
    );
    res.json(trips);
  } catch (error) {
    console.error('getTrips error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const setSchedule = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    if (!user?.team) { res.status(403).json({ error: 'No team assigned' }); return; }

    const { routeId, resourceId, thresholdQuantity, transportQuantity, frequencyHours } = req.body;
    if (!routeId || !resourceId) {
      res.status(400).json({ error: 'routeId and resourceId required' });
      return;
    }

    await execute(
      `INSERT INTO scheduled_logistics (route_id, resource_id, threshold_quantity, transport_quantity, frequency_hours)
       VALUES (?, ?, ?, ?, ?)`,
      [routeId, resourceId, thresholdQuantity ?? 100, transportQuantity ?? 50, frequencyHours ?? 24],
    );
    res.status(201).json({ message: 'Schedule created' });
  } catch (error) {
    console.error('setSchedule error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};
