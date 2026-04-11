import { Request, Response } from 'express';
import { RowDataPacket } from 'mysql2/promise';
import { query } from '../databaseConfig.js';
import { AuthenticatedRequest } from '../middleware/auth.js';

export const getMap = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;

    // Get all locations
    const locations = await query<RowDataPacket[]>('SELECT * FROM locations ORDER BY id');

    // Apply fog of war: limit info for non-team locations
    const mapData = locations.map(loc => {
      const isTeamLocation = user?.team && loc.team === user.team;
      const isUnowned = !loc.team;

      if (isTeamLocation || user?.role === 'admin' || user?.role === 'gamemaster') {
        return loc; // full info
      }

      // Fog of war: show only basic info for enemy/neutral locations
      return {
        id: loc.id,
        name: isUnowned ? loc.name : '???',
        type: loc.type,
        hex_x: loc.hex_x,
        hex_y: loc.hex_y,
        team: loc.team,
        // Hide population, happiness, storage details
      };
    });

    res.json(mapData);
  } catch (error) {
    console.error('getMap error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const getLocations = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    let locations: RowDataPacket[];

    if (user?.role === 'admin' || user?.role === 'gamemaster') {
      locations = await query<RowDataPacket[]>('SELECT * FROM locations ORDER BY id');
    } else if (user?.team) {
      // Show own team locations fully, others with limited info
      locations = await query<RowDataPacket[]>(
        `SELECT * FROM locations WHERE team = ? OR team IS NULL ORDER BY id`,
        [user.team],
      );
    } else {
      locations = await query<RowDataPacket[]>(
        'SELECT id, name, type, hex_x, hex_y FROM locations ORDER BY id',
      );
    }

    res.json(locations);
  } catch (error) {
    console.error('getLocations error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const getLocationDetail = async (req: Request, res: Response): Promise<void> => {
  try {
    const locationId = parseInt(req.params.locationId, 10);
    if (isNaN(locationId)) {
      res.status(400).json({ error: 'Invalid location ID' });
      return;
    }

    const user = (req as AuthenticatedRequest).user;

    const locations = await query<RowDataPacket[]>(
      'SELECT * FROM locations WHERE id = ?',
      [locationId],
    );
    if (locations.length === 0) {
      res.status(404).json({ error: 'Location not found' });
      return;
    }

    const location = locations[0];

    // Check access: team must own location or be admin/gamemaster
    const isOwner = user?.team && location.team === user.team;
    const isPrivileged = user?.role === 'admin' || user?.role === 'gamemaster';

    if (!isOwner && !isPrivileged && location.team) {
      res.json({
        id: location.id,
        name: '???',
        type: location.type,
        hex_x: location.hex_x,
        hex_y: location.hex_y,
        team: location.team,
      });
      return;
    }

    // Full details
    const [population, resources, buildings] = await Promise.all([
      query<RowDataPacket[]>(
        'SELECT * FROM population_groups WHERE location_id = ?',
        [locationId],
      ),
      query<RowDataPacket[]>(
        `SELECT lr.*, r.name AS resource_name, r.category
         FROM location_resources lr
         JOIN resources r ON r.id = lr.resource_id
         WHERE lr.location_id = ?`,
        [locationId],
      ),
      query<RowDataPacket[]>(
        'SELECT * FROM buildings WHERE location_id = ?',
        [locationId],
      ),
    ]);

    res.json({
      ...location,
      population,
      resources,
      buildings,
    });
  } catch (error) {
    console.error('getLocationDetail error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};
