import React from 'react';
import Dashboard from './components/Dashboard';
import LoginPage from './components/LoginPage';
import './styles/theme.css';

export default function App(){
  const authed = document.cookie.includes('PHPSESSID');
  return authed ? <Dashboard/> : <LoginPage/>;
}
