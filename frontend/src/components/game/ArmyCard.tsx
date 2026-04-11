import { Card, Badge, ProgressBar } from 'react-bootstrap';
import { GiTank, GiCannon } from 'react-icons/gi';
import { FaUsers, FaShieldAlt } from 'react-icons/fa';
import type { Army } from '../../types';

interface ArmyCardProps {
  army: Army;
  onClick?: (army: Army) => void;
}

const statusConfig: Record<string, { bg: string; pulse: boolean }> = {
  idle: { bg: 'secondary', pulse: false },
  moving: { bg: 'primary', pulse: false },
  in_combat: { bg: 'danger', pulse: true },
  retreating: { bg: 'warning', pulse: false },
};

function unitIcon(type: string) {
  switch (type.toLowerCase()) {
    case 'tank':
    case 'tanks':
    case 'armor':
      return <GiTank className="me-1" />;
    case 'artillery':
      return <GiCannon className="me-1" />;
    default:
      return <FaUsers className="me-1" />;
  }
}

export default function ArmyCard({ army, onClick }: ArmyCardProps) {
  const cfg = statusConfig[army.status] || statusConfig.idle;
  const teamColor = army.team === 'blue' ? '#1e90ff' : army.team === 'red' ? '#dc3545' : '#6c757d';

  return (
    <Card
      className="h-100"
      style={{
        backgroundColor: '#16213e',
        border: `1px solid ${teamColor}44`,
        color: '#ccd6f6',
        cursor: onClick ? 'pointer' : undefined,
      }}
      onClick={() => onClick?.(army)}
    >
      <Card.Body className="p-3">
        <div className="d-flex justify-content-between align-items-start mb-2">
          <div>
            <div className="d-flex align-items-center gap-2">
              <FaShieldAlt style={{ color: teamColor }} />
              <span style={{ fontWeight: 700, fontSize: '0.95rem' }}>{army.name}</span>
            </div>
            <small style={{ color: '#8892b0' }}>General: {army.general}</small>
          </div>
          <Badge
            bg={cfg.bg}
            style={{
              textTransform: 'capitalize',
              animation: cfg.pulse ? 'pulse-badge 1.2s infinite' : 'none',
            }}
          >
            {army.status.replace('_', ' ')}
          </Badge>
        </div>

        {/* Unit breakdown */}
        <div className="mb-2" style={{ fontSize: '0.8rem' }}>
          {army.units.map((u) => (
            <div key={u.id} className="d-flex justify-content-between">
              <span>
                {unitIcon(u.type)}
                <span style={{ textTransform: 'capitalize' }}>{u.type}</span>
              </span>
              <span style={{ color: '#64ffda' }}>{u.count.toLocaleString()}</span>
            </div>
          ))}
          <div
            className="d-flex justify-content-between mt-1 pt-1"
            style={{ borderTop: '1px solid #0f3460' }}
          >
            <span style={{ fontWeight: 600 }}>Total Strength</span>
            <span style={{ color: '#64ffda', fontWeight: 600 }}>
              {army.strength.toLocaleString()}
            </span>
          </div>
        </div>

        {/* Morale */}
        <div className="mb-2">
          <div className="d-flex justify-content-between" style={{ fontSize: '0.75rem' }}>
            <span style={{ color: '#8892b0' }}>Morale</span>
            <span>{army.morale}%</span>
          </div>
          <ProgressBar
            now={army.morale}
            variant={army.morale > 60 ? 'success' : army.morale > 30 ? 'warning' : 'danger'}
            style={{ height: 6, backgroundColor: '#0a0a1a' }}
          />
        </div>

        {/* Experience (average across units) */}
        {army.units.length > 0 && (() => {
          const avgExp = Math.round(
            army.units.reduce((s, u) => s + u.experience, 0) / army.units.length
          );
          return (
            <div className="mb-1">
              <div className="d-flex justify-content-between" style={{ fontSize: '0.75rem' }}>
                <span style={{ color: '#8892b0' }}>Experience</span>
                <span>{avgExp}%</span>
              </div>
              <ProgressBar
                now={avgExp}
                variant="info"
                style={{ height: 6, backgroundColor: '#0a0a1a' }}
              />
            </div>
          );
        })()}

        <div style={{ fontSize: '0.75rem', color: '#8892b0', marginTop: 6 }}>
          📍 {army.location} ({army.hex_q}, {army.hex_r})
        </div>
      </Card.Body>

      <style>{`
        @keyframes pulse-badge {
          0%, 100% { opacity: 1; }
          50% { opacity: 0.5; }
        }
      `}</style>
    </Card>
  );
}
