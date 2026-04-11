import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Form, Button, InputGroup } from 'react-bootstrap';
import { FaPaperPlane } from 'react-icons/fa';
import { chatAPI } from '../../services/api';
import { getSocket, onChatMessage, offChatMessage, emitSendChat } from '../../services/socket';
import { useAuth } from '../../context/AuthContext';
import type { ChatMessage } from '../../types';

interface TeamChatProps {
  maxHeight?: number;
  compact?: boolean;
}

export default function TeamChat({ maxHeight = 400, compact = false }: TeamChatProps) {
  const { team } = useAuth();
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState('');
  const listRef = useRef<HTMLDivElement>(null);

  const scrollToBottom = useCallback(() => {
    if (listRef.current) {
      listRef.current.scrollTop = listRef.current.scrollHeight;
    }
  }, []);

  useEffect(() => {
    chatAPI
      .getMessages(team ?? undefined)
      .then((res) => {
        setMessages(res.data);
        setTimeout(scrollToBottom, 50);
      })
      .catch(() => {});

    const handleMessage = (msg: ChatMessage) => {
      setMessages((prev) => [...prev, msg]);
      setTimeout(scrollToBottom, 50);
    };

    onChatMessage(handleMessage);
    return () => {
      offChatMessage(handleMessage);
    };
  }, [team, scrollToBottom]);

  const handleSend = (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = input.trim();
    if (!trimmed) return;

    const sock = getSocket();
    if (sock?.connected) {
      emitSendChat(trimmed);
    } else {
      chatAPI.sendMessage(trimmed).catch(() => {});
    }
    setInput('');
  };

  const teamNameColor = (t: string | null) =>
    t === 'blue' ? '#1e90ff' : t === 'red' ? '#dc3545' : '#8892b0';

  const formatTime = (ts: string) => {
    try {
      const d = new Date(ts);
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
      return '';
    }
  };

  if (compact) {
    const last3 = messages.slice(-3);
    return (
      <div style={{ fontSize: '0.78rem' }}>
        {last3.length === 0 && (
          <div style={{ color: '#555', fontStyle: 'italic' }}>No messages yet</div>
        )}
        {last3.map((m) => (
          <div key={m.id} className="mb-1" style={{ lineHeight: 1.3 }}>
            <span style={{ color: teamNameColor(m.team), fontWeight: 600 }}>
              {m.user}
            </span>
            <span style={{ color: '#8892b0' }}> · {formatTime(m.timestamp)} </span>
            <div style={{ color: '#ccd6f6' }}>{m.message}</div>
          </div>
        ))}
      </div>
    );
  }

  return (
    <div
      className="d-flex flex-column"
      style={{
        backgroundColor: '#0d1117',
        border: '1px solid #0f3460',
        borderRadius: 6,
        overflow: 'hidden',
      }}
    >
      <div
        className="px-3 py-2"
        style={{ backgroundColor: '#16213e', borderBottom: '1px solid #0f3460', fontWeight: 600, color: '#ccd6f6', fontSize: '0.85rem' }}
      >
        Team Chat
      </div>

      <div
        ref={listRef}
        className="px-3 py-2 flex-grow-1"
        style={{ maxHeight, overflowY: 'auto' }}
      >
        {messages.length === 0 && (
          <div className="text-center py-4" style={{ color: '#555' }}>
            No messages yet. Say hello!
          </div>
        )}
        {messages.map((m) => (
          <div key={m.id} className="mb-2" style={{ fontSize: '0.82rem' }}>
            <div className="d-flex align-items-center gap-2">
              {m.avatar_url && (
                <img
                  src={m.avatar_url}
                  alt=""
                  style={{ width: 20, height: 20, borderRadius: '50%' }}
                />
              )}
              <span style={{ color: teamNameColor(m.team), fontWeight: 600 }}>
                {m.user}
              </span>
              <span style={{ color: '#555', fontSize: '0.72rem' }}>
                {formatTime(m.timestamp)}
              </span>
            </div>
            <div style={{ color: '#ccd6f6', marginLeft: 28 }}>{m.message}</div>
          </div>
        ))}
      </div>

      <Form onSubmit={handleSend} className="p-2" style={{ borderTop: '1px solid #0f3460' }}>
        <InputGroup size="sm">
          <Form.Control
            type="text"
            placeholder="Type a message..."
            value={input}
            onChange={(e) => setInput(e.target.value)}
            style={{
              backgroundColor: '#1a1a2e',
              border: '1px solid #0f3460',
              color: '#ccd6f6',
            }}
          />
          <Button variant="outline-primary" type="submit" disabled={!input.trim()}>
            <FaPaperPlane />
          </Button>
        </InputGroup>
      </Form>
    </div>
  );
}
