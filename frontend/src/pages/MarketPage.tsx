import React, { useState, useEffect } from 'react';
import {
  Row, Col, Card, Table, Badge, Button, Form, InputGroup, Spinner,
} from 'react-bootstrap';
import { FaArrowUp, FaArrowDown, FaMinus, FaShoppingCart } from 'react-icons/fa';
import { marketAPI } from '../services/api';
import type { MarketPrice, MarketOrder, MarketTrend } from '../types';

const trendIcon: Record<MarketTrend, React.ReactNode> = {
  up: <FaArrowUp style={{ color: '#2ecc71' }} />,
  down: <FaArrowDown style={{ color: '#e74c3c' }} />,
  stable: <FaMinus style={{ color: '#8892b0' }} />,
};

export default function MarketPage() {
  const [prices, setPrices] = useState<MarketPrice[]>([]);
  const [orders, setOrders] = useState<MarketOrder[]>([]);
  const [loading, setLoading] = useState(true);

  // Trade form
  const [tradeType, setTradeType] = useState<'buy' | 'sell'>('buy');
  const [selectedResource, setSelectedResource] = useState<number | ''>('');
  const [quantity, setQuantity] = useState(0);

  const fetchData = async () => {
    try {
      const [pRes, oRes] = await Promise.all([marketAPI.getPrices(), marketAPI.getOrders()]);
      setPrices(pRes.data);
      setOrders(oRes.data);
    } catch {}
    setLoading(false);
  };

  useEffect(() => { fetchData(); }, []);

  const selectedPrice = prices.find((p) => p.resource_id === selectedResource);
  const totalCost = selectedPrice ? selectedPrice.price * quantity : 0;

  const handleTrade = async () => {
    if (!selectedResource || quantity <= 0) return;
    try {
      if (tradeType === 'buy') {
        await marketAPI.buy({ resource_id: selectedResource as number, quantity });
      } else {
        await marketAPI.sell({ resource_id: selectedResource as number, quantity });
      }
      setQuantity(0);
      fetchData();
    } catch {
      alert('Trade failed');
    }
  };

  const handleQuickBuy = async (resourceName: string) => {
    const price = prices.find((p) => p.name.toLowerCase() === resourceName.toLowerCase());
    if (!price) return;
    const qty = prompt(`Quick buy ${resourceName} — enter quantity:`);
    if (!qty || isNaN(Number(qty)) || Number(qty) <= 0) return;
    try {
      await marketAPI.buy({ resource_id: price.resource_id, quantity: Number(qty) });
      fetchData();
    } catch {
      alert('Purchase failed');
    }
  };

  const panelStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };
  const inputStyle = { backgroundColor: '#16213e', border: '1px solid #0f3460', color: '#ccd6f6' };

  if (loading) {
    return <div className="d-flex justify-content-center py-5"><Spinner animation="border" variant="light" /></div>;
  }

  return (
    <div>
      <Row className="g-3 mb-3">
        {/* Price Table */}
        <Col lg={8}>
          <Card style={panelStyle}>
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              Market Prices
            </Card.Header>
            <Card.Body className="p-0">
              <Table responsive variant="dark" className="mb-0" style={{ fontSize: '0.82rem' }}>
                <thead style={{ backgroundColor: '#0a0a1a' }}>
                  <tr>
                    <th>Resource</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Trend</th>
                    <th>24h Volume</th>
                  </tr>
                </thead>
                <tbody>
                  {prices.length === 0 ? (
                    <tr><td colSpan={5} className="text-center text-muted">No market data</td></tr>
                  ) : (
                    prices.map((p) => (
                      <tr key={p.resource_id}>
                        <td>{p.name}</td>
                        <td><Badge bg="dark" style={{ textTransform: 'capitalize' }}>{p.category}</Badge></td>
                        <td style={{ fontFamily: 'monospace', color: '#64ffda' }}>
                          ${p.price.toFixed(2)}
                        </td>
                        <td>{trendIcon[p.trend]}</td>
                        <td>{p.volume_24h.toLocaleString()}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </Table>
            </Card.Body>
          </Card>
        </Col>

        {/* Buy/Sell Form */}
        <Col lg={4}>
          <Card style={panelStyle}>
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
              <FaShoppingCart className="me-2" /> Trade
            </Card.Header>
            <Card.Body>
              <Form.Group className="mb-3">
                <div className="d-flex gap-2 mb-3">
                  <Button
                    variant={tradeType === 'buy' ? 'success' : 'outline-success'}
                    size="sm"
                    className="flex-fill"
                    onClick={() => setTradeType('buy')}
                  >
                    Buy
                  </Button>
                  <Button
                    variant={tradeType === 'sell' ? 'danger' : 'outline-danger'}
                    size="sm"
                    className="flex-fill"
                    onClick={() => setTradeType('sell')}
                  >
                    Sell
                  </Button>
                </div>
              </Form.Group>

              <Form.Group className="mb-3">
                <Form.Label style={{ fontSize: '0.85rem' }}>Resource</Form.Label>
                <Form.Select
                  value={selectedResource}
                  onChange={(e) => setSelectedResource(e.target.value ? Number(e.target.value) : '')}
                  style={inputStyle}
                >
                  <option value="">Select resource</option>
                  {prices.map((p) => (
                    <option key={p.resource_id} value={p.resource_id}>
                      {p.name} — ${p.price.toFixed(2)}/unit
                    </option>
                  ))}
                </Form.Select>
              </Form.Group>

              <Form.Group className="mb-3">
                <Form.Label style={{ fontSize: '0.85rem' }}>Quantity</Form.Label>
                <Form.Control
                  type="number"
                  min={0}
                  value={quantity}
                  onChange={(e) => setQuantity(Number(e.target.value))}
                  style={inputStyle}
                />
              </Form.Group>

              {selectedPrice && quantity > 0 && (
                <div
                  className="mb-3 p-2 rounded"
                  style={{ backgroundColor: '#0d1117', fontSize: '0.9rem', textAlign: 'center' }}
                >
                  Total: <strong style={{ color: '#64ffda' }}>${totalCost.toFixed(2)}</strong>
                </div>
              )}

              <Button
                variant={tradeType === 'buy' ? 'success' : 'danger'}
                className="w-100"
                onClick={handleTrade}
                disabled={!selectedResource || quantity <= 0}
              >
                {tradeType === 'buy' ? 'Buy' : 'Sell'} Resources
              </Button>
            </Card.Body>
          </Card>

          {/* Quick Buy */}
          <Card style={panelStyle} className="mt-3">
            <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700, fontSize: '0.9rem' }}>
              Quick Buy
            </Card.Header>
            <Card.Body className="d-flex flex-wrap gap-2">
              {['Fuel', 'Food', 'Coal', 'Steel'].map((name) => (
                <Button
                  key={name}
                  variant="outline-info"
                  size="sm"
                  onClick={() => handleQuickBuy(name)}
                >
                  {name}
                </Button>
              ))}
            </Card.Body>
          </Card>
        </Col>
      </Row>

      {/* Order History */}
      <Card style={panelStyle}>
        <Card.Header style={{ backgroundColor: '#0f3460', fontWeight: 700 }}>
          Order History
        </Card.Header>
        <Card.Body className="p-0">
          <Table responsive variant="dark" className="mb-0" style={{ fontSize: '0.82rem' }}>
            <thead style={{ backgroundColor: '#0a0a1a' }}>
              <tr>
                <th>Type</th>
                <th>Resource</th>
                <th>Qty</th>
                <th>Price/Unit</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {orders.length === 0 ? (
                <tr><td colSpan={7} className="text-center text-muted">No orders yet</td></tr>
              ) : (
                orders.map((o) => (
                  <tr key={o.id}>
                    <td>
                      <Badge bg={o.type === 'buy' ? 'success' : 'danger'} style={{ textTransform: 'uppercase' }}>
                        {o.type}
                      </Badge>
                    </td>
                    <td>{o.resource_name}</td>
                    <td>{o.quantity.toLocaleString()}</td>
                    <td style={{ fontFamily: 'monospace' }}>${o.price_per_unit.toFixed(2)}</td>
                    <td style={{ fontFamily: 'monospace', color: '#64ffda' }}>${o.total.toFixed(2)}</td>
                    <td>
                      <Badge bg={o.status === 'completed' ? 'success' : o.status === 'cancelled' ? 'secondary' : 'warning'}>
                        {o.status}
                      </Badge>
                    </td>
                    <td>{new Date(o.created_at).toLocaleDateString()}</td>
                  </tr>
                ))
              )}
            </tbody>
          </Table>
        </Card.Body>
      </Card>
    </div>
  );
}
