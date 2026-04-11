import { useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Spinner } from 'react-bootstrap';
import { useAuth } from '../context/AuthContext';

export default function AuthCallbackPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { checkAuth } = useAuth();

  useEffect(() => {
    const token = searchParams.get('token');
    if (token) {
      localStorage.setItem('bl_token', token);
      checkAuth().then(() => {
        navigate('/dashboard', { replace: true });
      });
    } else {
      navigate('/login', { replace: true });
    }
  }, [searchParams, navigate, checkAuth]);

  return (
    <div
      className="d-flex flex-column justify-content-center align-items-center vh-100"
      style={{ backgroundColor: '#1a1a2e', color: '#ccd6f6' }}
    >
      <Spinner animation="border" variant="light" className="mb-3" />
      <div style={{ fontSize: '0.9rem', color: '#8892b0' }}>Authenticating…</div>
    </div>
  );
}
