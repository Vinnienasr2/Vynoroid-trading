import React, {useEffect, useState} from 'react';
import {useSSE} from '../hooks/useSSE';

export default function Dashboard(){
  const balance = useSSE('/backend/api/balance.php', {balance:0,currency:'USD'});
  const stats = useSSE('/backend/api/stats.php', {total_wins:0,total_losses:0,total_runs:0,trades:[]});
  const status = useSSE('/backend/api/bot_status.php', {line:''});
  const [lines,setLines]=useState([]);
  useEffect(()=>{ if(status.line){ setLines(prev=>[...prev.slice(-199), status.line]); }},[status]);

  return <main className='dash'>
    <section className='bal card'><h1>${Number(balance.balance).toFixed(2)}</h1><span>{balance.currency}</span><p>Real-time balance — auto-updating</p></section>
    <section className='grid'>
      <div className='card'><h4>Total Wins</h4><b>{stats.total_wins}</b></div>
      <div className='card'><h4>Total Losses</h4><b>{stats.total_losses}</b></div>
      <div className='card'><h4>Total Runs</h4><b>{stats.total_runs}</b></div>
      <div className='card'><h4>Account Status</h4><b>{(stats.total_wins-stats.total_losses)>=0?'Growing 📈':'Declining 📉'}</b></div>
    </section>
    <section className='consoleWrap card'><button onClick={()=>setLines([])} className='clearBtn'>Clear Console</button><pre className='console'>{lines.join('\n')}</pre></section>
  </main>
}
