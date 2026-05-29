import React, { useState } from 'react';
import { LeaveScreen } from './LeaveScreen';
import { LeaveCreateScreen } from './LeaveCreateScreen';

/**
 * Switcher antara list dan create. Tidak pakai stack navigator karena flow
 * sederhana 2 screen, supaya bundle size mobile tetap ringan.
 */
export const LeaveModule: React.FC = () => {
  const [mode, setMode] = useState<'list' | 'create'>('list');
  return mode === 'list'
    ? <LeaveScreen onCreate={() => setMode('create')} />
    : <LeaveCreateScreen onClose={() => setMode('list')} />;
};