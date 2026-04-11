import { Request, Response } from 'express';
import { RESOURCES, PRODUCTION_RECIPES } from '../gameConstants.js';
import { getLocationResources } from '../resourceService.js';

export const getResources = (_req: Request, res: Response): void => {
  res.json(RESOURCES);
};

export const getLocationResourcesHandler = async (req: Request, res: Response): Promise<void> => {
  try {
    const locationId = parseInt(req.params.locationId, 10);
    if (isNaN(locationId)) {
      res.status(400).json({ error: 'Invalid location ID' });
      return;
    }
    const resources = await getLocationResources(locationId);
    res.json(resources);
  } catch (error) {
    console.error('getLocationResources error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const getProductionChains = (_req: Request, res: Response): void => {
  res.json(PRODUCTION_RECIPES);
};
