import React, { useState } from 'react';

import { LoginScreen } from './LoginScreen';
import { TwoFactorScreen } from './TwoFactorScreen';
import { ForgotPasswordScreen } from './ForgotPasswordScreen';
import type { TwoFactorChallenge } from './api';

type Mode =
  | { kind: 'login' }
  | { kind: '2fa'; challenge: TwoFactorChallenge }
  | { kind: 'forgot' };

/**
 * AuthStack — state machine 3-screen Login ↔ 2FA ↔ Forgot.
 * Tidak pakai @react-navigation supaya bundle ringan.
 */
export const AuthStack: React.FC = () => {
  const [mode, setMode] = useState<Mode>({ kind: 'login' });

  switch (mode.kind) {
    case '2fa':
      return (
        <TwoFactorScreen
          challenge={mode.challenge}
          onCancel={() => setMode({ kind: 'login' })}
        />
      );
    case 'forgot':
      return <ForgotPasswordScreen onClose={() => setMode({ kind: 'login' })} />;
    case 'login':
    default:
      return (
        <LoginScreen
          onTwoFactor={(challenge) => setMode({ kind: '2fa', challenge })}
          onForgotPassword={() => setMode({ kind: 'forgot' })}
        />
      );
  }
};