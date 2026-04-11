import React from 'react';
import { Navbar as BSNavbar, Nav, Container, Badge, Dropdown, Image } from 'react-bootstrap';
import { Link, useLocation } from 'react-router-dom';
import { FaSignOutAlt, FaShieldAlt, FaCog } from 'react-icons/fa';
import { useAuth } from '../../context/AuthContext';
import { useGame } from '../../context/GameContext';
import TickCounter from '../game/TickCounter';
import WeatherWidget from '../game/WeatherWidget';

const Navbar: React.FC = () => {
  const { user, logout, team, role } = useAuth();
  const { isConnected } = useGame();
  const location = useLocation();

  const teamColor = team === 'blue' ? '#1e90ff' : team === 'red' ? '#dc3545' : '#6c757d';
  const teamLabel = team === 'blue' ? 'BLUE TEAM' : team === 'red' ? 'RED TEAM' : 'UNASSIGNED';

  const navLinks = [
    { path: '/dashboard', label: 'Dashboard', roles: null },
    { path: '/map', label: 'Map', roles: null },
    { path: '/resources', label: 'Resources', roles: null },
    { path: '/logistics', label: 'Logistics', roles: null },
    { path: '/military', label: 'Military', roles: null },
    { path: '/market', label: 'Market', roles: null },
    { path: '/farms', label: 'Farms', roles: null },
    { path: '/espionage', label: 'Espionage', roles: null },
    { path: '/rules', label: 'Rules', roles: null },
    { path: '/admin', label: 'Admin', roles: ['admin', 'gamemaster'] as string[] },
  ];

  const filteredLinks = navLinks.filter(
    (l) => !l.roles || (role && l.roles.includes(role))
  );

  return (
    <BSNavbar
      variant="dark"
      expand="lg"
      fixed="top"
      className="bl-navbar"
      style={{ background: 'linear-gradient(135deg, #0f0f23 0%, #16213e 100%)', borderBottom: '2px solid ' + teamColor }}
    >
      <Container fluid>
        <BSNavbar.Brand as={Link} to="/dashboard" className="d-flex align-items-center">
          <FaShieldAlt size={28} className="me-2" style={{ color: teamColor }} />
          <span className="fw-bold" style={{ fontFamily: "'Oswald', sans-serif", letterSpacing: '3px', fontSize: '1.2rem' }}>
            BLACK LEGION
          </span>
        </BSNavbar.Brand>

        <BSNavbar.Toggle aria-controls="main-nav" />

        <BSNavbar.Collapse id="main-nav">
          <Nav className="me-auto">
            {filteredLinks.map((link) => (
              <Nav.Link
                key={link.path}
                as={Link}
                to={link.path}
                className={location.pathname === link.path ? 'active' : ''}
                style={{
                  color: location.pathname === link.path ? teamColor : '#b0b0b0',
                  fontWeight: location.pathname === link.path ? 700 : 400,
                  textTransform: 'uppercase',
                  fontSize: '0.8rem',
                  letterSpacing: '1px',
                }}
              >
                {link.label}
              </Nav.Link>
            ))}
          </Nav>

          <div className="d-flex align-items-center gap-3">
            <WeatherWidget compact />
            <TickCounter />

            <Badge
              pill
              bg="none"
              style={{ backgroundColor: teamColor, fontSize: '0.7rem', letterSpacing: '1px' }}
            >
              {teamLabel}
            </Badge>

            <span
              className="d-inline-block rounded-circle"
              style={{
                width: 8,
                height: 8,
                backgroundColor: isConnected ? '#00ff88' : '#ff4444',
                boxShadow: isConnected ? '0 0 6px #00ff88' : '0 0 6px #ff4444',
              }}
              title={isConnected ? 'Connected' : 'Disconnected'}
            />

            {user && (
              <Dropdown align="end">
                <Dropdown.Toggle
                  variant="link"
                  className="d-flex align-items-center text-decoration-none p-0"
                  style={{ color: '#e0e0e0' }}
                >
                  <Image
                    src={user.avatar_url || '/default-avatar.png'}
                    roundedCircle
                    width={32}
                    height={32}
                    className="me-2"
                    style={{ border: `2px solid ${teamColor}` }}
                  />
                  <span className="d-none d-md-inline" style={{ fontSize: '0.85rem' }}>
                    {user.username}
                  </span>
                </Dropdown.Toggle>
                <Dropdown.Menu className="bg-dark border-secondary">
                  <Dropdown.Header className="text-muted" style={{ fontSize: '0.75rem' }}>
                    {role?.toUpperCase()}
                  </Dropdown.Header>
                  {(role === 'admin' || role === 'gamemaster') && (
                    <Dropdown.Item as={Link} to="/admin" className="text-light">
                      <FaCog className="me-2" /> Admin Panel
                    </Dropdown.Item>
                  )}
                  <Dropdown.Divider className="border-secondary" />
                  <Dropdown.Item onClick={logout} className="text-danger">
                    <FaSignOutAlt className="me-2" /> Logout
                  </Dropdown.Item>
                </Dropdown.Menu>
              </Dropdown>
            )}
          </div>
        </BSNavbar.Collapse>
      </Container>
    </BSNavbar>
  );
};

export default Navbar;
