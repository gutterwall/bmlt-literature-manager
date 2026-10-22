#!/usr/bin/env python3
"""A minimal SMTP server for the end-to-end test: accepts every message and saves it as <n>.eml in a folder.

Usage: smtp-sink.py <port> <folder>

Speaks enough SMTP for PHPMailer (EHLO/HELO, MAIL, RCPT, DATA, RSET, NOOP, QUIT); no TLS, no authentication.
The envelope recipients are written as X-Envelope-To headers at the top of each file. Standard library only.
"""
import asyncio, os, sys

PORT, OUT = int(sys.argv[1]), sys.argv[2]
os.makedirs(OUT, exist_ok=True)
count = 0


async def session(reader, writer):
    global count
    send = lambda line: writer.write((line + '\r\n').encode())
    send('220 e2e-sink ESMTP')
    rcpts, data = [], None
    while True:
        line = await reader.readline()
        if not line:
            break
        if data is not None:
            if line in (b'.\r\n', b'.\n'):
                count += 1
                head = ''.join('X-Envelope-To: %s\r\n' % r for r in rcpts).encode()
                with open(os.path.join(OUT, '%03d.eml' % count), 'wb') as f:
                    f.write(head + b''.join(data))
                rcpts, data = [], None
                send('250 OK queued')
            else:
                data.append(line[1:] if line.startswith(b'..') else line)
            await writer.drain()
            continue
        cmd = line.decode(errors='replace').strip()
        verb = cmd.split(' ', 1)[0].upper()
        if verb == 'EHLO':
            send('250-e2e-sink')
            send('250 8BITMIME')
        elif verb in ('HELO', 'NOOP'):
            send('250 OK')
        elif verb == 'MAIL':
            rcpts = []
            send('250 OK')
        elif verb == 'RCPT':
            rcpts.append(cmd.split(':', 1)[1].strip().strip('<>'))
            send('250 OK')
        elif verb == 'DATA':
            data = []
            send('354 End data with <CR><LF>.<CR><LF>')
        elif verb == 'RSET':
            rcpts, data = [], None
            send('250 OK')
        elif verb == 'QUIT':
            send('221 Bye')
            await writer.drain()
            break
        else:
            send('502 Not implemented')
        await writer.drain()
    writer.close()


async def main():
    server = await asyncio.start_server(session, '127.0.0.1', PORT)
    async with server:
        await server.serve_forever()

asyncio.run(main())
