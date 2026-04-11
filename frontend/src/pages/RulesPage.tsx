import { useState, useEffect } from 'react';
import { Card, Tab, Tabs, Form, Button, Spinner, Alert } from 'react-bootstrap';
import { FaBook, FaSave } from 'react-icons/fa';
import { useAuth } from '../context/AuthContext';
import { adminAPI } from '../services/api';

export default function RulesPage() {
  const { role } = useAuth();
  const [teamRules, setTeamRules] = useState('');
  const [gmRules, setGmRules] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null);

  const isAdmin = role === 'admin' || role === 'gamemaster';
  const isGamemaster = role === 'gamemaster';

  useEffect(() => {
    const fetch = async () => {
      try {
        const [tRes, gRes] = await Promise.all([
          adminAPI.getRules('team'),
          adminAPI.getRules('gamemaster'),
        ]);
        setTeamRules(tRes.data.content || '');
        setGmRules(gRes.data.content || '');
      } catch {}
      setLoading(false);
    };
    fetch();
  }, []);

  const handleSave = async (type: 'team' | 'gamemaster') => {
    setSaving(true);
    setSaveSuccess(null);
    try {
      const content = type === 'team' ? teamRules : gmRules;
      await adminAPI.updateRules(type, content);
      setSaveSuccess(`${type === 'team' ? 'Team' : 'Gamemaster'} rules saved successfully`);
      setTimeout(() => setSaveSuccess(null), 3000);
    } catch {
      alert('Failed to save rules');
    }
    setSaving(false);
  };

  const panelStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };

  if (loading) {
    return <div className="d-flex justify-content-center py-5"><Spinner animation="border" variant="light" /></div>;
  }

  return (
    <div>
      <Card style={panelStyle}>
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          <FaBook className="me-2" /> Campaign Rules
        </Card.Header>
        <Card.Body>
          {saveSuccess && <Alert variant="success" style={{ fontSize: '0.85rem' }}>{saveSuccess}</Alert>}

          <Tabs defaultActiveKey="team" className="mb-3" variant="pills">
            {/* Team Rules */}
            <Tab eventKey="team" title="Team Rules">
              <Form.Group>
                <Form.Label style={{ fontSize: '0.85rem', color: '#8892b0' }}>
                  Rules shared with your team. {isAdmin ? 'You can edit these.' : 'Read-only.'}
                </Form.Label>
                <Form.Control
                  as="textarea"
                  rows={18}
                  value={teamRules}
                  onChange={(e) => setTeamRules(e.target.value)}
                  readOnly={!isAdmin}
                  style={{
                    backgroundColor: '#0d1117',
                    border: '1px solid #0f3460',
                    color: '#ccd6f6',
                    fontFamily: 'monospace',
                    fontSize: '0.85rem',
                    resize: 'vertical',
                  }}
                />
              </Form.Group>
              {isAdmin && (
                <Button
                  variant="success"
                  size="sm"
                  className="mt-3"
                  onClick={() => handleSave('team')}
                  disabled={saving}
                >
                  <FaSave className="me-1" /> {saving ? 'Saving...' : 'Save Team Rules'}
                </Button>
              )}
            </Tab>

            {/* Gamemaster Rules */}
            <Tab eventKey="gamemaster" title="Gamemaster Rules">
              <Form.Group>
                <Form.Label style={{ fontSize: '0.85rem', color: '#8892b0' }}>
                  Official campaign rules. {isGamemaster ? 'You can edit these.' : 'Read-only.'}
                </Form.Label>
                <Form.Control
                  as="textarea"
                  rows={18}
                  value={gmRules}
                  onChange={(e) => setGmRules(e.target.value)}
                  readOnly={!isGamemaster}
                  style={{
                    backgroundColor: '#0d1117',
                    border: '1px solid #0f3460',
                    color: '#ccd6f6',
                    fontFamily: 'monospace',
                    fontSize: '0.85rem',
                    resize: 'vertical',
                  }}
                />
              </Form.Group>
              {isGamemaster && (
                <Button
                  variant="success"
                  size="sm"
                  className="mt-3"
                  onClick={() => handleSave('gamemaster')}
                  disabled={saving}
                >
                  <FaSave className="me-1" /> {saving ? 'Saving...' : 'Save Gamemaster Rules'}
                </Button>
              )}
            </Tab>
          </Tabs>
        </Card.Body>
      </Card>
    </div>
  );
}
