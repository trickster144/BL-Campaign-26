import { Request, Response } from 'express';
import { getGameStateFromDB, updateGameStateInDB } from '../models/gameModel.js';

export const getGameState = async (req: Request, res: Response) => {
  try {
    const gameState = await getGameStateFromDB();
    res.json(gameState);
  } catch (error) {
    console.error('Error fetching game state:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};

export const updateGameState = async (req: Request, res: Response) => {
  try {
    // TODO: Add gamemaster role check
    const updates = req.body;
    const updatedState = await updateGameStateInDB(updates);
    res.json(updatedState);
  } catch (error) {
    console.error('Error updating game state:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
};