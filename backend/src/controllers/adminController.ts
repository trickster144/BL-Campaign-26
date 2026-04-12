import { Request, Response } from 'express';
import { RowDataPacket } from 'mysql2/promise';
import { query, execute } from '../databaseConfig.js';
import { AuthenticatedRequest } from '../middleware/auth.js';
import { normalizeRole, toDatabaseRole } from '../roleUtils.js';

// ── User management ──────────────────────────────────────────────────────────

export const getUsers = async (_req: Request, res: Response): Promise<void> => {
  try {
    const users = await query<RowDataPacket[]>(
      'SELECT id, steam_id, username, avatar_url, team, role, is_active, last_login, created_at FROM users ORDER BY id',
    );
    res.json(users.map((user) => ({
      ...user,
      role: normalizeRole(String(user.role)),
    })));
  } catch (error) {
    console.error('getUsers error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const updateUserRole = async (req: Request, res: Response): Promise<void> => {
  try {
    const userId = parseInt(req.params.userId, 10);
    const { role } = req.body;

    if (!role || !['admin', 'member', 'observer', 'gamemaster'].includes(role)) {
      res.status(400).json({ error: 'Valid role required (admin, member, observer, gamemaster)' });
      return;
    }

    const users = await query<(RowDataPacket & { team: 'blue' | 'red' | null })[]>(
      'SELECT team FROM users WHERE id = ?',
      [userId],
    );
    if (users.length === 0) {
      res.status(404).json({ error: 'User not found' });
      return;
    }

    const dbRole = toDatabaseRole(users[0].team, role);
    if (!dbRole) {
      res.status(400).json({ error: 'Assign a team before setting a non-gamemaster role' });
      return;
    }

    await execute('UPDATE users SET role = ? WHERE id = ?', [dbRole, userId]);
    res.json({ message: 'Role updated' });
  } catch (error) {
    console.error('updateUserRole error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const approveUser = async (req: Request, res: Response): Promise<void> => {
  try {
    const userId = parseInt(req.params.userId, 10);
    const { team } = req.body;

    if (!team || !['blue', 'red'].includes(team)) {
      res.status(400).json({ error: 'Valid team required (blue, red)' });
      return;
    }

    await execute(
      'UPDATE users SET team = ?, role = ? WHERE id = ?',
      [team, toDatabaseRole(team, 'member'), userId],
    );
    res.json({ message: 'User approved and assigned to team' });
  } catch (error) {
    console.error('approveUser error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

// ── Gamemaster overrides ─────────────────────────────────────────────────────

export const gamemasterAction = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as AuthenticatedRequest).user;
    const { action, params } = req.body;

    if (!action) {
      res.status(400).json({ error: 'Action required' });
      return;
    }

    let result: unknown;

    switch (action) {
      case 'start_game': {
        await execute('UPDATE game_state SET game_started = TRUE, start_date = NOW() WHERE id = 1');
        result = { message: 'Game started' };
        break;
      }

      case 'pause_game': {
        await execute('UPDATE game_state SET game_started = FALSE WHERE id = 1');
        result = { message: 'Game paused' };
        break;
      }

      case 'set_weather': {
        const { weatherData } = params ?? {};
        if (weatherData) {
          await execute('UPDATE game_state SET weather_data = ? WHERE id = 1', [JSON.stringify(weatherData)]);
          result = { message: 'Weather updated' };
        }
        break;
      }

      case 'add_resources': {
        const { locationId, resourceName, quantity } = params ?? {};
        if (locationId && resourceName && quantity) {
          // Direct resource injection
          const resRows = await query<(RowDataPacket & { id: number })[]>(
            'SELECT id FROM resources WHERE name = ?',
            [resourceName],
          );
          if (resRows.length > 0) {
            await execute(
              `INSERT INTO location_resources (location_id, resource_id, quantity)
               VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE quantity = quantity + ?`,
              [locationId, resRows[0].id, quantity, quantity],
            );
            result = { message: `Added ${quantity} ${resourceName} to location ${locationId}` };
          }
        }
        break;
      }

      case 'assign_location': {
        const { locationId, team } = params ?? {};
        if (locationId && team) {
          await execute('UPDATE locations SET team = ? WHERE id = ?', [team, locationId]);
          result = { message: `Location ${locationId} assigned to ${team}` };
        }
        break;
      }

      case 'damage_building': {
        const { buildingId, amount } = params ?? {};
        if (buildingId && amount) {
          await execute(
            'UPDATE buildings SET health = GREATEST(health - ?, 0) WHERE id = ?',
            [amount, buildingId],
          );
          result = { message: `Building ${buildingId} damaged by ${amount}` };
        }
        break;
      }

      case 'modify_population': {
        const { locationId, ageGroup, count } = params ?? {};
        if (locationId && ageGroup && count !== undefined) {
          await execute(
            'UPDATE population_groups SET `count` = ? WHERE location_id = ? AND age_group = ?',
            [count, locationId, ageGroup],
          );
          result = { message: `Population updated at location ${locationId}` };
        }
        break;
      }

      case 'add_funds': {
        const { team, amount } = params ?? {};
        if (team && amount) {
          await execute(`
            CREATE TABLE IF NOT EXISTS team_treasury (
              team ENUM('blue','red') PRIMARY KEY,
              balance DECIMAL(15,2) DEFAULT 100000.00,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
          `);
          await execute(
            `INSERT INTO team_treasury (team, balance) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE balance = balance + ?`,
            [team, amount, amount],
          );
          result = { message: `Added ${amount} to ${team} treasury` };
        }
        break;
      }

      default:
        res.status(400).json({ error: `Unknown action: ${action}` });
        return;
    }

    // Log the action
    await execute(
      `INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)`,
      [user?.id ?? null, `gm:${action}`, JSON.stringify(params ?? {})],
    );

    res.json(result);
  } catch (error) {
    console.error('gamemasterAction error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};
