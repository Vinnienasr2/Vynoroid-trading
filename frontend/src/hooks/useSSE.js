import {useEffect, useState} from 'react';
export function useSSE(url, initial){
  const [data,setData]=useState(initial);
  useEffect(()=>{const ev=new EventSource(url); ev.onmessage=e=>setData(JSON.parse(e.data)); return ()=>ev.close();},[url]);
  return data;
}
