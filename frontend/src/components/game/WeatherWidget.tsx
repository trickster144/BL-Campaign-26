import React from 'react';
import { Badge } from 'react-bootstrap';
import {
  WiDaySunny,
  WiCloudy,
  WiRain,
  WiSnow,
  WiStrongWind,
  WiFog,
} from 'react-icons/wi';
import { useGame } from '../../context/GameContext';
import type { WeatherState, WeatherType } from '../../types';

interface WeatherWidgetProps {
  weather?: WeatherState;
  compact?: boolean;
}

const weatherIcons: Record<WeatherType, React.ReactNode> = {
  clear: <WiDaySunny size={22} color="#ffd700" />,
  cloudy: <WiCloudy size={22} color="#b0b0b0" />,
  rain: <WiRain size={22} color="#5dade2" />,
  storm: <WiStrongWind size={22} color="#8e44ad" />,
  snow: <WiSnow size={22} color="#ecf0f1" />,
  fog: <WiFog size={22} color="#95a5a6" />,
  blizzard: <WiSnow size={22} color="#aed6f1" />,
};

function modBadge(label: string, value: number) {
  if (value === 1) return null;
  const isBonus = value > 1;
  return (
    <Badge
      bg={isBonus ? 'success' : 'danger'}
      className="me-1"
      style={{ fontSize: '0.65rem' }}
    >
      {label} {isBonus ? '+' : ''}{Math.round((value - 1) * 100)}%
    </Badge>
  );
}

export default function WeatherWidget(props: WeatherWidgetProps) {
  const game = useGame();
  const weather = props.weather ?? game.weather;
  const compact = props.compact ?? false;

  if (!weather) return null;

  return (
    <div className="d-flex align-items-center gap-2">
      {weatherIcons[weather.type] || <WiDaySunny size={22} />}
      <span style={{ color: '#e0e0e0', fontSize: '0.85rem' }}>
        {weather.temperature}°C
      </span>
      {!compact && (
        <div className="d-flex align-items-center ms-1">
          {modBadge('Crop', weather.effects.crop_modifier)}
          {modBadge('Travel', weather.effects.travel_modifier)}
          {modBadge('Combat', weather.effects.combat_modifier)}
        </div>
      )}
    </div>
  );
}
