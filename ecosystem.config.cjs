// PM2 process file: one long-polling Telegram bot per bank plus the scheduler
// that runs transactions:check every minute. Bots whose Telegram token is not
// configured exit immediately; PM2 keeps them stopped until the token is added.
const banks = ['andalus', 'nuran', 'jumhouria', 'nab', 'atib'];

const app = (name, args) => ({
    name,
    script: 'artisan',
    args,
    interpreter: 'php',
    cwd: __dirname,
    autorestart: true,
    restart_delay: 5000,
    max_restarts: 10,
    time: true,
});

module.exports = {
    apps: [
        ...banks.map((bank) => app(`${bank}-bot`, `bot:run ${bank}`)),
        app('bank-bot-schedule', 'schedule:work'),
    ],
};
