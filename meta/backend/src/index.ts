import express, { Request, Response } from 'express';
import cors from 'cors';
import dotenv from 'dotenv';

dotenv.config();

const app = express();
const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json());

// Rota de Teste
app.get('/', (req: Request, res: Response) => {
  res.send('Meta Integration API is Running! 🚀');
});

/**
 * Webhook Verification (Meta requirements)
 */
app.get('/webhook', (req: Request, res: Response) => {
  const mode = req.query['hub.mode'];
  const token = req.query['hub.verify_token'];
  const challenge = req.query['hub.challenge'];

  if (mode && token) {
    if (mode === 'subscribe' && token === process.env.VERIFY_TOKEN) {
      console.log('Webhook Verified! ✅');
      res.status(200).send(challenge);
    } else {
      res.sendStatus(403);
    }
  } else {
    res.sendStatus(400);
  }
});

/**
 * Webhook Events
 */
app.post('/webhook', (req: Request, res: Response) => {
  const body = req.body;

  // Verifica se o evento é da API do Messenger/WhatsApp/Ads (ajustar conforme necessidade)
  if (body.object) {
    console.log('Webhook Event Received:', JSON.stringify(body, null, 2));
    res.status(200).send('EVENT_RECEIVED');
  } else {
    res.sendStatus(404);
  }
});

app.listen(PORT, () => {
  console.log(`Server is running on http://localhost:${PORT}`);
});
