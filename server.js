import express from 'express';
import fetch from 'node-fetch';
import { Twilio } from 'twilio';


const app = express();
const PORT = 3000;

// Twilio credentials
const accountSid = 'Twilio_sid';
const authToken = 'Twilio_auth';
const twilioClient = new Twilio(accountSid, authToken);
const twilioNumber = 'Twilio_number';
const phoneNumber = 'To_number';  // recipient phone number

app.use(express.json());

app.post('/send-message', async (req, res) => {
    const { message } = req.body;

    try {
        // Send message to Groq API
        const groqApiUrl = 'https://api.groq.com/message';
        const groqResponse = await fetch(`${groqApiUrl}?message=${encodeURIComponent(message)}`);
        const groqData = await groqResponse.json();
        const botReply = groqData.reply || 'Sorry, I did not understand that.';

        // Send reply via Twilio
        await twilioClient.messages.create({
            body: botReply,
            from: twilioNumber,
            to: phoneNumber,
        });

        res.json({ reply: botReply });
    } catch (error) {
        console.error('Error:', error);
        res.status(500).json({ error: 'Failed to send message' });
    }
});

app.listen(PORT, () => {
    console.log(`Server is running on http://localhost:${PORT}`);
});
