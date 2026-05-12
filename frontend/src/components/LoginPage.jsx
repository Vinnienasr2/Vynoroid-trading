import React from 'react';
export default function LoginPage(){
  return <div className='login-bg'><div className='brand'>DerivBot Pro <span className='dot'/></div><div className='card'><h2>Connect Your Deriv Account</h2><p>Authorize securely via Deriv OAuth2 to begin automated trading</p><a className='cta' href='/backend/auth/login.php'>🔒 Connect with Deriv</a><small>Your credentials are never stored. OAuth2 secured.</small></div></div>
}
