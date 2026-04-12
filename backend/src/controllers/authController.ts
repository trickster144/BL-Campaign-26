import { Request, Response } from 'express';
import jwt from 'jsonwebtoken';
import { query, execute } from '../databaseConfig.js';
import { RowDataPacket } from 'mysql2/promise';

// ── Types ────────────────────────────────────────────────────────────────────
interface UserRow extends RowDataPacket {
  id: number;
  steam_id: string;
  username: string;
  avatar_url: string | null;
  team: 'blue' | 'red' | null;
  role: string;
  is_active: boolean;
}

const JWT_SECRET = process.env.JWT_SECRET || 'default-secret';
const STEAM_API_KEY = process.env.STEAM_API_KEY || '';
const FRONTEND_URL = process.env.FRONTEND_URL || 'http://10.0.0.28:10011';

// ── Steam Login Redirect ─────────────────────────────────────────────────────

export const steamLogin = (_req: Request, res: Response): void => {
  const returnUrl = encodeURIComponent(`${_req.protocol}://${_req.get('host')}/api/auth/steam/callback`);
  const steamUrl = `https://steamcommunity.com/openid/login?openid.ns=http://specs.openid.net/auth/2.0`
    + `&openid.mode=checkid_setup`
    + `&openid.return_to=${returnUrl}`
    + `&openid.realm=${encodeURIComponent(`${_req.protocol}://${_req.get('host')}`)}`
    + `&openid.identity=http://specs.openid.net/auth/2.0/identifier_select`
    + `&openid.claimed_id=http://specs.openid.net/auth/2.0/identifier_select`;
  res.redirect(steamUrl);
};

// ── Steam Callback ───────────────────────────────────────────────────────────

export const steamCallback = async (req: Request, res: Response): Promise<void> => {
  try {
    const claimedId = req.query['openid.claimed_id'] as string;
    if (!claimedId) {
      res.redirect(`${FRONTEND_URL}/login?error=no_steam_id`);
      return;
    }

    // Extract Steam ID from claimed identity URL
    const steamIdMatch = claimedId.match(/\/id\/(\d+)$/);
    const steamId = steamIdMatch ? steamIdMatch[1] : claimedId.split('/').pop() || '';

    if (!steamId) {
      res.redirect(`${FRONTEND_URL}/login?error=invalid_steam_id`);
      return;
    }

    // Fetch Steam profile
    let username = `Player_${steamId.slice(-6)}`;
    let avatarUrl: string | null = null;

    if (STEAM_API_KEY) {
      try {
        const response = await fetch(
          `https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=${STEAM_API_KEY}&steamids=${steamId}`,
        );
        const data = await response.json() as { response: { players: { personaname: string; avatarfull: string }[] } };
        if (data.response?.players?.[0]) {
          username = data.response.players[0].personaname;
          avatarUrl = data.response.players[0].avatarfull;
        }
      } catch { /* use defaults */ }
    }

    // Upsert user
    const existingUsers = await query<UserRow[]>(
      'SELECT * FROM users WHERE steam_id = ?',
      [steamId],
    );

    let userId: number;
    let role: string;
    let team: string | null;

    if (existingUsers.length > 0) {
      userId = existingUsers[0].id;
      role = existingUsers[0].role;
      team = existingUsers[0].team;
      await execute(
        'UPDATE users SET username = ?, avatar_url = ?, last_login = NOW() WHERE id = ?',
        [username, avatarUrl, userId],
      );
    } else {
      const result = await execute(
        `INSERT INTO users (steam_id, username, avatar_url, role, last_login)
         VALUES (?, ?, ?, 'observer', NOW())`,
        [steamId, username, avatarUrl],
      );
      userId = result.insertId;
      role = 'observer';
      team = null;
    }

    // Generate JWT
    const token = jwt.sign(
      { id: userId, steam_id: steamId, username, team, role },
      JWT_SECRET,
      { expiresIn: '7d' },
    );

    res.redirect(`${FRONTEND_URL}/auth/callback?token=${token}`);
  } catch (error) {
    console.error('Steam callback error:', error);
    res.redirect(`${FRONTEND_URL}/login?error=auth_failed`);
  }
};

// ── Get Profile ──────────────────────────────────────────────────────────────

export const getProfile = async (req: Request, res: Response): Promise<void> => {
  try {
    const user = (req as any).user;
    if (!user) {
      res.status(401).json({ error: 'Not authenticated' });
      return;
    }

    const users = await query<UserRow[]>('SELECT * FROM users WHERE id = ?', [user.id]);
    if (users.length === 0) {
      res.status(404).json({ error: 'User not found' });
      return;
    }

    const u = users[0];
    res.json({
      id: u.id,
      steam_id: u.steam_id,
      username: u.username,
      avatar_url: u.avatar_url,
      team: u.team,
      role: u.role,
      is_active: u.is_active,
    });
  } catch (error) {
    console.error('getProfile error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

// ── Logout ───────────────────────────────────────────────────────────────────

export const logout = (_req: Request, res: Response): void => {
  // JWT is stateless; client must discard token
  res.json({ message: 'Logged out successfully' });
};
