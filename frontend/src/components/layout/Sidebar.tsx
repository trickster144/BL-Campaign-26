import React, { useState, useEffect } from 'react';
import { Badge, ListGroup, Spinner } from 'react-bootstrap';
import {
  FaChevronLeft, FaChevronRight, FaUsers, FaCubes,
  FaTruck, FaShieldAlt, FaBell, FaComments,
} from 'react-icons/fa';
import { useAuth } from '../../context/AuthContext';
import { useGame } from '../../context/GameContext';
import type { Notification } from '../../types';

const Sidebar: React.FC = () => {
  const { team } = useAuth();
  const { gameState } = useGame();
  const [collapsed, setCollapsed] = useState(false);
  const [notifications, setNotifications] = useState<Notification[]>([]);

  const teamColor = team === 'blue' ? '#1e90ff' : team === 'red' ? '#dc3545' : '#6c757d';

  // Simulated quick-stats (would be fetched from API in production)
  const quickStats = {
    population: 12450,
    resources: 847,
    vehicles: 23,
    armyStrength: 3200,
  };

  useEffect(() => {
    // Example notifications – real app would fetch from API/socket
    setNotifications([
      { id: 1, type: 'warning', title: 'Low Fuel', message: 'Fuel reserves below 20%', timestamp: new Date().toISOString(), read: false },
      { id: 2, type: 'danger', title: 'Battle Alert', message: 'Enemy forces detected near Sector 7', timestamp: new Date().toISOString(), read: false },
      { id: 3, type: 'info', title: 'Trade Complete', message: 'Market order #142 fulfilled', timestamp: new Date().toISOString(), read: true },
    ]);
  }, []);

  const unreadCount = notifications.filter((n) => !n.read).length;

  return (
    <div
      className="bl-sidebar d-flex flex-column"
      style={{
        width: collapsed ? 50 : 260,
        minHeight: 'calc(100vh - 62px)',
        background: 'linear-gradient(180deg, #0f0f23 0%, #1a1a2e 100%)',
        borderRight: `1px solid ${teamColor}33`,
        transition: 'width 0.3s ease',
        position: 'fixed',
        top: 62,
        left: 0,
        zIndex: 1000,
        overflowY: 'auto',
        overflowX: 'hidden',
      }}
    >
      {/* Collapse toggle */}
      <button
        onClick={() => setCollapsed(!collapsed)}
        className="btn btn-sm w-100 text-muted border-0 py-2"
        style={{ background: 'rgba(255,255,255,0.03)' }}
      >
        {collapsed ? <FaChevronRight /> : <FaChevronLeft />}
      </button>

      {!collapsed && (
        <>
          {/* Quick Stats */}
          <div className="px-3 py-2">
            <h6 className="text-uppercase text-muted mb-3" style={{ fontSize: '0.7rem', letterSpacing: '2px' }}>
              Quick Stats
            </h6>
            <div className="d-flex flex-column gap-2">
              <StatRow icon={<FaUsers />} label="Population" value={quickStats.population.toLocaleString()} color={teamColor} />
              <StatRow icon={<FaCubes />} label="Resources" value={quickStats.resources.toString()} color="#f0ad4e" />
              <StatRow icon={<FaTruck />} label="Vehicles" value={quickStats.vehicles.toString()} color="#5bc0de" />
              <StatRow icon={<FaShieldAlt />} label="Army" value={quickStats.armyStrength.toLocaleString()} color="#d9534f" />
            </div>
          </div>

          <hr className="border-secondary mx-3 my-2" />

          {/* Notifications */}
          <div className="px-3 py-2">
            <h6 className="text-uppercase text-muted mb-2 d-flex align-items-center" style={{ fontSize: '0.7rem', letterSpacing: '2px' }}>
              <FaBell className="me-2" />
              Alerts
              {unreadCount > 0 && (
                <Badge bg="danger" pill className="ms-auto" style={{ fontSize: '0.65rem' }}>
                  {unreadCount}
                </Badge>
              )}
            </h6>
            <ListGroup variant="flush">
              {notifications.slice(0, 5).map((n) => (
                <ListGroup.Item
                  key={n.id}
                  className="border-0 px-2 py-1"
                  style={{
                    background: 'transparent',
                    color: n.read ? '#666' : '#ccc',
                    fontSize: '0.75rem',
                  }}
                >
                  <span
                    className="d-inline-block rounded-circle me-2"
                    style={{
                      width: 6,
                      height: 6,
                      backgroundColor:
                        n.type === 'danger' ? '#dc3545' :
                        n.type === 'warning' ? '#ffc107' :
                        n.type === 'success' ? '#28a745' : '#17a2b8',
                    }}
                  />
                  <strong>{n.title}:</strong> {n.message}
                </ListGroup.Item>
              ))}
            </ListGroup>
          </div>

          <hr className="border-secondary mx-3 my-2" />

          {/* Chat preview */}
          <div className="px-3 py-2">
            <h6 className="text-uppercase text-muted mb-2 d-flex align-items-center" style={{ fontSize: '0.7rem', letterSpacing: '2px' }}>
              <FaComments className="me-2" />
              Team Chat
            </h6>
            <div style={{ fontSize: '0.75rem', color: '#888' }}>
              <p className="mb-1"><strong style={{ color: teamColor }}>Commander:</strong> Move the convoy north.</p>
              <p className="mb-1"><strong style={{ color: teamColor }}>Logistics:</strong> Fuel resupply en route.</p>
              <p className="mb-0 text-muted fst-italic">Type in team chat to coordinate...</p>
            </div>
          </div>

          {/* Game tick info at bottom */}
          <div className="mt-auto px-3 py-3 text-center" style={{ fontSize: '0.7rem', color: '#555' }}>
            {gameState ? (
              <>Tick {gameState.current_tick} &bull; {gameState.season}</>
            ) : (
              <Spinner animation="border" size="sm" variant="secondary" />
            )}
          </div>
        </>
      )}
    </div>
  );
};

interface StatRowProps {
  icon: React.ReactNode;
  label: string;
  value: string;
  color: string;
}

const StatRow: React.FC<StatRowProps> = ({ icon, label, value, color }) => (
  <div className="d-flex align-items-center justify-content-between" style={{ fontSize: '0.8rem' }}>
    <span className="text-muted d-flex align-items-center gap-2">
      <span style={{ color }}>{icon}</span>
      {label}
    </span>
    <span className="fw-bold" style={{ color }}>{value}</span>
  </div>
);

export default Sidebar;
