import { Response, NextFunction } from 'express';
import { AuthenticatedRequest } from './auth.js';

export const requireTeam = (req: AuthenticatedRequest, res: Response, next: NextFunction): void => {
  if (!req.user) {
    res.status(401).json({ error: 'Authentication required' });
    return;
  }

  if (!req.user.team) {
    res.status(403).json({ error: 'Team membership required' });
    return;
  }

  next();
};
