#!/usr/bin/env node
/** Run on the browser's own computer, never as a remote WordPress callback. Node 20+. */
import { createServer } from 'node:http';
import { randomBytes, createHash, createPublicKey, verify, timingSafeEqual } from 'node:crypto';
import { readFile, writeFile, rename } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
const auth = 'https://auth.openai.com';
export function prepare(hostId, port, previous = {}) {
  if (!/^urn:uuid:[a-f0-9-]{36}$/i.test(hostId)) throw new Error('Use the host ID shown in Ace AI settings.');
  const state = randomBytes(32).toString('base64url'), nonce = randomBytes(32).toString('base64url'), verifier = randomBytes(48).toString('base64url');
  const redirect = `http://127.0.0.1:${port}/auth/callback`;
  const query = new URLSearchParams({ client_id: previous.client_id || 'dynamic_agent_client', ext_agent_host_id: hostId, response_type: 'code', redirect_uri: redirect, scope: 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct', resource: 'https://api.openai.com/v1', state, nonce, code_challenge_method: 'S256', code_challenge: createHash('sha256').update(verifier).digest('base64url') });
  if (!previous.client_id) query.set('agent_name_hint', 'Ace AI');
  return { state, nonce, verifier, redirect, hostId, previous, url: `${auth}/api/accounts/authorize?${query}` };
}
export function callback(pending, query) {
  const returned = query.get('state') || '';
  if (returned.length !== pending.state.length || !timingSafeEqual(Buffer.from(returned), Buffer.from(pending.state))) throw new Error('Sign-in state did not match.');
  if (query.has('error')) throw new Error('Sign-in was declined or unavailable.');
  const client = query.get('client_id') || pending.previous.client_id;
  if (!client || client === 'dynamic_agent_client' || (pending.previous.client_id && client !== pending.previous.client_id)) throw new Error('The issued client registration did not match.');
  if (!query.get('code')) throw new Error('The sign-in code was missing.');
  return { grant_type: 'authorization_code', client_id: client, code: query.get('code'), code_verifier: pending.verifier, redirect_uri: pending.redirect, resource: 'https://api.openai.com/v1' };
}
export function identity(jwt, client, nonce, jwks, previousSubject = '', now = Math.floor(Date.now()/1000)) {
  const parts = jwt.split('.'); if(parts.length !== 3) throw new Error('Invalid account identity.');
  const header = JSON.parse(Buffer.from(parts[0], 'base64url')), claims = JSON.parse(Buffer.from(parts[1], 'base64url'));
  const jwk = jwks.keys.find(k => k.kid === header.kid && k.kty === 'RSA' && (!k.use || k.use === 'sig') && (!k.alg || k.alg === 'RS256'));
  if(header.alg !== 'RS256' || !jwk || !verify('RSA-SHA256', Buffer.from(`${parts[0]}.${parts[1]}`), createPublicKey({ key:jwk, format:'jwk' }), Buffer.from(parts[2],'base64url'))) throw new Error('Account signature could not be verified.');
  const aud = Array.isArray(claims.aud) ? claims.aud : [claims.aud];
  if(claims.iss !== auth || !aud.includes(client) || (aud.length > 1 && claims.azp !== client) || (claims.azp && claims.azp !== client) || typeof claims.exp !== 'number' || claims.exp <= now || (claims.iat || 0) > now+60 || (claims.nbf || 0) > now+60 || claims.nonce !== nonce || !claims.sub || (previousSubject && previousSubject !== claims.sub)) throw new Error('Account identity or expiry did not match.');
  return claims;
}
async function jsonRequest(url, options = {}) {
  const response = await fetch(url, { ...options, redirect:'error', signal:AbortSignal.timeout(30000) });
  if(!response.ok) throw new Error('OpenAI could not complete this sign-in. Start a fresh attempt.');
  return response.json();
}
async function atomic(path, data) {
  const temporary = `${path}.${randomBytes(8).toString('hex')}.tmp`;
  await writeFile(temporary, JSON.stringify(data,null,2), {mode:0o600, flag:'wx'}); await rename(temporary,path);
}
async function main() {
  const [hostId, output, registrationFile] = process.argv.slice(2);
  if(!hostId || !output) throw new Error('Usage: node ace-chatgpt-login.mjs HOST_ID PRIVATE_OUTPUT_FILE [PREVIOUS_REGISTRATION_FILE]');
  const previous = registrationFile ? JSON.parse(await readFile(registrationFile,'utf8')) : {};
  let pending, handling = false;
  const server = createServer(async(req,res) => {
    const url = new URL(req.url,'http://127.0.0.1');
    if(url.pathname !== '/auth/callback' || handling) {res.writeHead(404).end();return;}
    // Reject unsolicited requests without consuming the pending attempt.
    if(url.searchParams.get('state') !== pending.state) {res.writeHead(400).end('Sign-in state did not match.');return;}
    handling = true;
    try {
      const body=callback(pending,url.searchParams);
      const tokens=await jsonRequest(`${auth}/api/accounts/oauth/token`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(body)});
      const jwks=await jsonRequest(`${auth}/.well-known/jwks.json`);
      const claims=identity(tokens.id_token,body.client_id,pending.nonce,jwks,previous.subject||'');
      const scopes=(tokens.scope||'').split(/\s+/);
      if(tokens.token_type?.toLowerCase() !== 'bearer' || !scopes.includes('chatgpt.tokens.use.direct') || !tokens.access_token || !tokens.refresh_token || !(tokens.expires_in > 0)) throw new Error('ChatGPT plan access was not granted.');
      const savedAt=Math.floor(Date.now()/1000);
      await atomic(output,{...tokens,scopes,client_id:body.client_id,subject:claims.sub,email:claims.email||'',issuer:claims.iss,ext_agent_host_id:hostId,validated_nonce:pending.nonce,saved_at:savedAt,expires_at:savedAt+Number(tokens.expires_in)});
      await atomic(`${output}.registration`,{client_id:body.client_id,subject:claims.sub});
      res.writeHead(200,{'Content-Type':'text/plain','Cache-Control':'no-store'}).end('Sign-in complete. Return to Ace AI settings to import the protected credential file.');
      console.log('Protected credentials saved. Transfer/import privately into the intended Ace site or network; never paste tokens into chat.');
    } catch(error) {res.writeHead(400,{'Content-Type':'text/plain','Cache-Control':'no-store'}).end('Sign-in did not complete. Return to the helper and start again.');console.error(error.message);process.exitCode=1;}
    finally {clearTimeout(timer);server.close();}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  pending=prepare(hostId,server.address().port,previous);
  console.log('Open this one-time sign-in URL in the browser on this computer:\n'+pending.url);
  const timer=setTimeout(()=>{console.error('Sign-in expired. Start again.');server.close();process.exitCode=1;},300000);
}
if(process.argv[1] && import.meta.url===pathToFileURL(process.argv[1]).href) main().catch(error=>{console.error(error.message);process.exitCode=1;});
