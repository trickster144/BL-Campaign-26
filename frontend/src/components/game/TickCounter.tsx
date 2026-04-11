// TickCounter - Real-time game tick display
import { useGame } from '../../context/GameContext';

interface TickCounterProps {
  tick?: number;
  isConnected?: boolean;
}

const TICKS_PER_HOUR = 60;
const TICKS_PER_DAY = 1440;

export default function TickCounter(props: TickCounterProps) {
  const game = useGame();
  const tick = props.tick ?? game.gameState?.current_tick ?? 0;
  const isConnected = props.isConnected ?? game.isConnected;

  const day = Math.floor(tick / TICKS_PER_DAY) + 1;
  const hour = Math.floor((tick % TICKS_PER_DAY) / TICKS_PER_HOUR);

  return (
    <div className="d-flex align-items-center gap-2">
      <span
        style={{
          width: 8,
          height: 8,
          borderRadius: '50%',
          backgroundColor: isConnected ? '#00ff41' : '#ff4444',
          display: 'inline-block',
          animation: isConnected ? 'blink-dot 1.4s infinite' : 'none',
        }}
      />
      <span
        style={{
          fontFamily: '"Courier New", Courier, monospace',
          fontSize: '0.85rem',
          color: '#e0e0e0',
          letterSpacing: '0.05em',
        }}
      >
        TICK #{String(tick).padStart(5, '0')}
      </span>
      <span
        style={{
          fontSize: '0.75rem',
          color: '#8892b0',
          borderLeft: '1px solid #334',
          paddingLeft: 8,
          marginLeft: 2,
        }}
      >
        Day {day}, Hour {String(hour).padStart(2, '0')}
      </span>

      <style>{`
        @keyframes blink-dot {
          0%, 100% { opacity: 1; }
          50% { opacity: 0.2; }
        }
      `}</style>
    </div>
  );
}
