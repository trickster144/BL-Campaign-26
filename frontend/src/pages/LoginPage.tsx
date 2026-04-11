import React from 'react';
import { Container, Button, Row, Col } from 'react-bootstrap';
import { FaSteam, FaShieldAlt } from 'react-icons/fa';
import { useAuth } from '../context/AuthContext';
import { Navigate } from 'react-router-dom';

const LoginPage: React.FC = () => {
  const { isAuthenticated, login, isLoading } = useAuth();

  if (isAuthenticated) return <Navigate to="/dashboard" replace />;

  return (
    <div
      className="min-vh-100 d-flex align-items-center justify-content-center"
      style={{
        background: 'radial-gradient(ellipse at center, #16213e 0%, #0f0f23 50%, #0a0a14 100%)',
        position: 'relative',
        overflow: 'hidden',
      }}
    >
      {/* Background grid effect */}
      <div
        style={{
          position: 'absolute',
          inset: 0,
          backgroundImage: `
            linear-gradient(rgba(30,144,255,0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(30,144,255,0.03) 1px, transparent 1px)
          `,
          backgroundSize: '50px 50px',
          pointerEvents: 'none',
        }}
      />

      <Container style={{ maxWidth: 500, position: 'relative', zIndex: 1 }}>
        <div className="text-center mb-5">
          {/* Logo */}
          <div className="mb-4">
            <FaShieldAlt
              size={80}
              style={{
                color: '#1e90ff',
                filter: 'drop-shadow(0 0 20px rgba(30,144,255,0.4))',
              }}
            />
          </div>
          <h1
            style={{
              fontFamily: "'Oswald', sans-serif",
              fontWeight: 700,
              fontSize: '3rem',
              letterSpacing: '8px',
              color: '#e0e0e0',
              textShadow: '0 0 30px rgba(30,144,255,0.3)',
            }}
          >
            BLACK LEGION
          </h1>
          <div
            style={{
              fontFamily: "'Oswald', sans-serif",
              fontSize: '1rem',
              letterSpacing: '4px',
              color: '#888',
              textTransform: 'uppercase',
            }}
          >
            Cold War Campaign
          </div>
        </div>

        <Row className="justify-content-center mb-4">
          <Col xs={10} md={8}>
            <p className="text-center mb-4" style={{ color: '#8892b0', fontSize: '0.9rem', lineHeight: 1.6 }}>
              Command your forces in a strategic Cold War simulation. Lead your team
              to victory through logistics, espionage, military operations, and
              economic warfare across a hex-based theater of operations.
            </p>
          </Col>
        </Row>

        <div className="text-center">
          <Button
            size="lg"
            onClick={login}
            disabled={isLoading}
            className="px-5 py-3"
            style={{
              background: 'linear-gradient(135deg, #171a21 0%, #1b2838 100%)',
              border: '2px solid #66c0f4',
              color: '#c7d5e0',
              fontWeight: 600,
              fontSize: '1.1rem',
              letterSpacing: '1px',
              borderRadius: '6px',
              transition: 'all 0.3s ease',
            }}
            onMouseOver={(e) => {
              e.currentTarget.style.background = 'linear-gradient(135deg, #1b2838 0%, #2a475e 100%)';
              e.currentTarget.style.boxShadow = '0 0 20px rgba(102,192,244,0.3)';
            }}
            onMouseOut={(e) => {
              e.currentTarget.style.background = 'linear-gradient(135deg, #171a21 0%, #1b2838 100%)';
              e.currentTarget.style.boxShadow = 'none';
            }}
          >
            <FaSteam className="me-3" size={24} />
            Sign in with Steam
          </Button>
        </div>

        <div className="text-center mt-5">
          <small style={{ color: '#555', fontSize: '0.75rem' }}>
            Black Legion &copy; {new Date().getFullYear()} — All rights reserved
          </small>
        </div>
      </Container>
    </div>
  );
};

export default LoginPage;
