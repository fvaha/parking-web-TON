import React, { useEffect, useRef, useState, useCallback } from 'react';
import type { ParkingSpace } from '../types';

interface BottomSheetProps {
  is_open: boolean;
  on_close: () => void;
  space: ParkingSpace | null;
  license_plate: string;
  children: React.ReactNode;
}

export const BottomSheet: React.FC<BottomSheetProps> = ({
  is_open,
  on_close,
  space,
  license_plate,
  children
}) => {
  const sheet_ref = useRef<HTMLDivElement>(null);
  const [is_dragging, set_is_dragging] = useState(false);
  const drag_state_ref = useRef({ start_y: 0, current_y: 0 });

  useEffect(() => {
    if (is_open && sheet_ref.current) {
      // Small delay to trigger animation
      setTimeout(() => {
        if (sheet_ref.current) {
          sheet_ref.current.style.transform = 'translateY(0)';
        }
      }, 10);
    } else if (!is_open && sheet_ref.current) {
      sheet_ref.current.style.transform = 'translateY(100%)';
    }
  }, [is_open]);

  const handle_touch_start = (e: React.TouchEvent) => {
    if (!sheet_ref.current) return;
    set_is_dragging(true);
    drag_state_ref.current.start_y = e.touches[0].clientY;
    drag_state_ref.current.current_y = e.touches[0].clientY;
  };

  const handle_touch_move = (e: React.TouchEvent) => {
    if (!is_dragging || !sheet_ref.current) return;
    const current = e.touches[0].clientY;
    drag_state_ref.current.current_y = current;
    const diff = current - drag_state_ref.current.start_y;
    if (diff > 0) {
      sheet_ref.current.style.transform = `translateY(${diff}px)`;
    }
  };

  const handle_touch_end = () => {
    if (!is_dragging || !sheet_ref.current) return;
    const diff = drag_state_ref.current.current_y - drag_state_ref.current.start_y;
    if (diff > 100) {
      on_close();
    } else {
      sheet_ref.current.style.transform = 'translateY(0)';
    }
    set_is_dragging(false);
  };

  const handle_mouse_down = (e: React.MouseEvent) => {
    if (!sheet_ref.current) return;
    set_is_dragging(true);
    drag_state_ref.current.start_y = e.clientY;
    drag_state_ref.current.current_y = e.clientY;
  };

  const handle_mouse_move = useCallback((e: MouseEvent) => {
    if (!is_dragging || !sheet_ref.current) return;
    const current = e.clientY;
    drag_state_ref.current.current_y = current;
    const diff = current - drag_state_ref.current.start_y;
    if (diff > 0) {
      sheet_ref.current.style.transform = `translateY(${diff}px)`;
    }
  }, [is_dragging]);

  const handle_mouse_up = useCallback(() => {
    if (!is_dragging || !sheet_ref.current) return;
    const diff = drag_state_ref.current.current_y - drag_state_ref.current.start_y;
    if (diff > 100) {
      on_close();
    } else {
      sheet_ref.current.style.transform = 'translateY(0)';
    }
    set_is_dragging(false);
  }, [is_dragging, on_close]);

  useEffect(() => {
    if (is_dragging) {
      document.addEventListener('mousemove', handle_mouse_move);
      document.addEventListener('mouseup', handle_mouse_up);
      return () => {
        document.removeEventListener('mousemove', handle_mouse_move);
        document.removeEventListener('mouseup', handle_mouse_up);
      };
    }
  }, [is_dragging, handle_mouse_move, handle_mouse_up]);

  if (!is_open) return null;

  return (
    <>
      {/* Backdrop */}
      <div
        className="bottom-sheet-backdrop"
        onClick={on_close}
        style={{
          position: 'fixed',
          top: 0,
          left: 0,
          width: '100%',
          height: '100%',
          backgroundColor: 'rgba(0, 0, 0, 0.5)',
          zIndex: 10000,
          animation: 'fadeIn 0.3s ease'
        }}
      />
      
      {/* Bottom Sheet */}
      <div
        ref={sheet_ref}
        className="bottom-sheet"
        onTouchStart={handle_touch_start}
        onTouchMove={handle_touch_move}
        onTouchEnd={handle_touch_end}
        style={{
          position: 'fixed',
          bottom: 0,
          left: 0,
          right: 0,
          backgroundColor: 'white',
          borderTopLeftRadius: '24px',
          borderTopRightRadius: '24px',
          boxShadow: '0 -4px 20px rgba(0, 0, 0, 0.15)',
          zIndex: 10001,
          maxHeight: '90vh',
          overflowY: 'auto',
          transform: 'translateY(100%)',
          transition: is_dragging ? 'none' : 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
          paddingBottom: 'env(safe-area-inset-bottom)'
        }}
      >
        {/* Drag Handle */}
        <div
          onMouseDown={handle_mouse_down}
          style={{
            width: '100%',
            padding: '12px 0',
            display: 'flex',
            justifyContent: 'center',
            alignItems: 'center',
            cursor: 'grab',
            userSelect: 'none'
          }}
        >
          <div
            style={{
              width: '40px',
              height: '4px',
              backgroundColor: '#d1d5db',
              borderRadius: '2px'
            }}
          />
        </div>

        {/* Content */}
        <div style={{ padding: '0 1.5rem 1.5rem 1.5rem' }}>
          {children}
        </div>
      </div>
    </>
  );
};

