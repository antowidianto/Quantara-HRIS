const express = require('express');
const axios = require('axios');

const app = express();
const PORT = process.env.PORT || 3000;
const HRIS_API_URL = process.env.HRIS_API_URL || 'http://localhost:8080/api/v1/attendance/machine-sync';
const ADMS_API_KEY = process.env.ADMS_API_KEY || 'default-adms-secret';

// Use text parser because ADMS sends raw text data
app.use(express.text({ type: '*/*' }));

// Handshake endpoint for ADMS (machine asks for configuration)
app.get('/iclock/cdata', (req, res) => {
    // The machine usually sends SN (Serial Number) via query params
    const sn = req.query.SN;
    console.log(`[Handshake] Machine connected: ${sn}`);
    
    // Return standard ADMS handshake response
    res.send(`GET OPTION FROM: ${sn}\nStamp=9999\nOpStamp=9999\nErrorDelay=60\nDelay=30\nTransTimes=00:00;14:00\nTransInterval=1\nTransFlag=1111000000\nTimeZone=73\nRealtime=1\nEncrypt=0`);
});

// Data push endpoint (machine sends attendance logs)
app.post('/iclock/cdata', async (req, res) => {
    const sn = req.query.SN;
    const rawData = req.body;
    
    console.log(`[Data Push] Received from ${sn}:`);
    console.log(rawData);

    // Parse ADMS raw text format.
    // Example format per line: PIN \t Time \t VerifyCode \t Status
    // e.g. "101    2023-10-25 08:00:00    1    0"
    const lines = rawData.split('\n');
    const logs = [];

    lines.forEach(line => {
        const parts = line.split('\t');
        if (parts.length >= 2) {
            logs.push({
                pin: parts[0].trim(),
                timestamp: parts[1].trim()
            });
        }
    });

    if (logs.length > 0) {
        try {
            // Forward the parsed data to CodeIgniter HRIS
            const response = await axios.post(HRIS_API_URL, {
                device_sn: sn,
                logs: logs
            }, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-Api-Key': ADMS_API_KEY
                }
            });

            console.log(`[Forwarded to HRIS] Success:`, response.data);
            
            // Return OK to machine so it clears its internal log buffer
            res.send('OK');
        } catch (error) {
            console.error(`[Forward to HRIS] Failed:`, error.response ? error.response.data : error.message);
            // Even if HRIS fails, we might return ERROR to machine so it retries later
            res.send('ERROR');
        }
    } else {
        res.send('OK');
    }
});

app.listen(PORT, () => {
    console.log(`ADMS Middleware running on port ${PORT}`);
    console.log(`Forwarding logs to: ${HRIS_API_URL}`);
});
