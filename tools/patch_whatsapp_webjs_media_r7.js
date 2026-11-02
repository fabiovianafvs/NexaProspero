'use strict';

const fs = require('fs');
const path = require('path');

const serviceDir = process.argv[2] || '/opt/nexa-whatsapp-service';
const packageRoot = path.join(serviceDir, 'node_modules', 'whatsapp-web.js');
const packageJson = path.join(packageRoot, 'package.json');

function die(message) {
  console.error(`ERRO: ${message}`);
  process.exit(1);
}

if (!fs.existsSync(packageJson)) die(`whatsapp-web.js não encontrado em ${packageRoot}`);
const pkg = JSON.parse(fs.readFileSync(packageJson, 'utf8'));
if (pkg.version !== '1.34.7') {
  die(`patch R7 homologado somente para whatsapp-web.js 1.34.7. Versão encontrada: ${pkg.version || 'desconhecida'}`);
}

const files = {
  base: path.join(packageRoot, 'src', 'structures', 'Base.js'),
  chat: path.join(packageRoot, 'src', 'structures', 'Chat.js'),
  message: path.join(packageRoot, 'src', 'structures', 'Message.js'),
  utils: path.join(packageRoot, 'src', 'util', 'Injected', 'Utils.js'),
};
for (const file of Object.values(files)) if (!fs.existsSync(file)) die(`arquivo esperado não encontrado: ${file}`);

const stamp = new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
function backup(file) {
  const dst = `${file}.bak-nexa-r7-${stamp}`;
  fs.copyFileSync(file, dst);
  return dst;
}
function replaceOnce(text, oldValue, newValue, label) {
  if (text.includes(newValue)) return { text, changed: false };
  const idx = text.indexOf(oldValue);
  if (idx < 0) die(`padrão não encontrado em ${label}; nenhum arquivo foi alterado nesta etapa.`);
  return { text: text.slice(0, idx) + newValue + text.slice(idx + oldValue.length), changed: true };
}
function saveIfChanged(file, oldText, newText) {
  if (oldText === newText) return false;
  const bak = backup(file);
  fs.writeFileSync(file, newText, 'utf8');
  console.log(`PATCH: ${path.relative(packageRoot, file)} (backup: ${bak})`);
  return true;
}

let changed = 0;

// Base.js: normaliza WID quando o WhatsApp Web entrega $1 no lugar de _serialized.
{
  const file = files.base;
  const oldText = fs.readFileSync(file, 'utf8');
  let text = oldText;
  if (!text.includes('static _normalizeId(id)')) {
    const oldBlock = `    _patch(data) {\n        return data;\n    }\n`;
    const newBlock = `    _patch(data) {\n        return data;\n    }\n\n    /**\n     * Normalizes a WhatsApp ID object so that _serialized is always defined.\n     * WhatsApp Web changed _serialized to $1 in July 2026.\n     * @param {object} id\n     * @returns {object}\n     */\n    static _normalizeId(id) {\n        if (id && id._serialized == null && id.$1 != null) {\n            return Object.assign({}, id, { _serialized: id.$1 });\n        }\n        return id;\n    }\n`;
    text = replaceOnce(text, oldBlock, newBlock, 'Base.js').text;
  }
  changed += saveIfChanged(file, oldText, text) ? 1 : 0;
}

// Message.js: garante _serialized/from/to/author compatíveis.
{
  const file = files.message;
  const oldText = fs.readFileSync(file, 'utf8');
  let text = oldText;
  const reps = [
    ['        this.id = data.id;', '        this.id = Base._normalizeId(data.id);'],
    ['                ? data.from._serialized\n', '                ? (data.from._serialized || data.from.$1)\n'],
    ['                ? data.to._serialized\n', '                ? (data.to._serialized || data.to.$1)\n'],
    ['                ? data.author._serialized\n', '                ? (data.author._serialized || data.author.$1)\n'],
  ];
  for (const [a,b] of reps) text = replaceOnce(text, a, b, 'Message.js').text;
  changed += saveIfChanged(file, oldText, text) ? 1 : 0;
}

// Chat.js: normaliza o ID do chat. A parte de fetchMessages do PR upstream não é necessária
// para o envio de documento e fica fora deste hotfix conservador.
{
  const file = files.chat;
  const oldText = fs.readFileSync(file, 'utf8');
  let text = oldText;
  text = replaceOnce(text, '        this.id = data.id;', '        this.id = Base._normalizeId(data.id);', 'Chat.js').text;
  changed += saveIfChanged(file, oldText, text) ? 1 : 0;
}

// Utils.js: normaliza os IDs serializados que atravessam a ponte Puppeteer/WhatsApp Web
// e torna getChat tolerante à coleção atual baseada em $1.
{
  const file = files.utils;
  const oldText = fs.readFileSync(file, 'utf8');
  let text = oldText;
  text = replaceOnce(
    text,
    `        if (typeof msg.id.remote === 'object') {\n            msg.id = Object.assign({}, msg.id, {\n                remote: msg.id.remote._serialized,\n            });\n        }\n\n        delete msg.pendingAckUpdate;`,
    `        if (typeof msg.id.remote === 'object') {\n            msg.id = Object.assign({}, msg.id, {\n                remote: msg.id.remote._serialized || msg.id.remote.$1,\n            });\n        }\n\n        // WhatsApp Web changed _serialized to $1 in July 2026 update.\n        if (msg.id && msg.id._serialized == null && msg.id.$1 != null) {\n            msg.id = Object.assign({}, msg.id, { _serialized: msg.id.$1 });\n        }\n\n        delete msg.pendingAckUpdate;`,
    'Utils.js/getMessageModel',
  ).text;

  const oldGetChat = `    window.WWebJS.getChat = async (chatId, { getAsModel = true } = {}) => {\n        const isChannel = /@\\w*newsletter\\b/.test(chatId);\n        const chatWid = window.require('WAWebWidFactory').createWid(chatId);\n        let chat;\n        if (isChannel) {\n            try {\n                chat = window\n                    .require('WAWebCollections')\n                    .WAWebNewsletterCollection.get(chatId);\n                if (!chat) {\n                    await window\n                        .require('WAWebLoadNewsletterPreviewChatAction')\n                        .loadNewsletterPreviewChat(chatId);\n                    chat = await window\n                        .require('WAWebCollections')\n                        .WAWebNewsletterCollection.find(chatWid);\n                }\n            } catch (ignoredError) {\n                chat = null;\n            }\n        } else {\n            chat =\n                window.require('WAWebCollections').Chat.get(chatWid) ||\n                (\n                    await window\n                        .require('WAWebFindChatAction')\n                        .findOrCreateLatestChat(chatWid)\n                )?.chat;\n        }\n        return getAsModel && chat\n            ? await window.WWebJS.getChatModel(chat, { isChannel: isChannel })\n            : chat;\n    };`;

  const newGetChat = `    window.WWebJS.getChat = async (chatId, { getAsModel = true } = {}) => {\n        try {\n            const isChannel = /@\\w*newsletter\\b/.test(chatId);\n            const chatWid = window.require('WAWebWidFactory').createWid(chatId);\n            let chat;\n\n            if (isChannel) {\n                try {\n                    chat = window\n                        .require('WAWebCollections')\n                        .WAWebNewsletterCollection.get(chatId);\n                    if (!chat) {\n                        await window\n                            .require('WAWebLoadNewsletterPreviewChatAction')\n                            .loadNewsletterPreviewChat(chatId);\n                        chat = await window\n                            .require('WAWebCollections')\n                            .WAWebNewsletterCollection.find(chatWid);\n                    }\n                } catch (ignoredError) {\n                    chat = null;\n                }\n            } else {\n                try {\n                    chat =\n                        window.require('WAWebCollections').Chat.get(chatWid) ||\n                        window.require('WAWebCollections').Chat.getModelsArray().find(c => (c.id._serialized || c.id.$1) === chatId) ||\n                        (\n                            await window\n                                .require('WAWebFindChatAction')\n                                .findOrCreateLatestChat(chatWid)\n                        )?.chat;\n                } catch (ignoredError) {\n                    chat = window.require('WAWebCollections').Chat.getModelsArray().find(c => (c.id._serialized || c.id.$1) === chatId) || null;\n                }\n            }\n\n            return getAsModel && chat\n                ? await window.WWebJS.getChatModel(chat, { isChannel: isChannel })\n                : chat;\n        } catch (err) {\n            return null;\n        }\n    };`;
  text = replaceOnce(text, oldGetChat, newGetChat, 'Utils.js/getChat').text;
  changed += saveIfChanged(file, oldText, text) ? 1 : 0;
}

// Valida sintaxe dos quatro arquivos alterados usando o parser do Node.
for (const file of Object.values(files)) {
  try {
    new Function(fs.readFileSync(file, 'utf8'));
  } catch (e) {
    die(`sintaxe inválida após patch em ${file}: ${e.message}`);
  }
}

console.log(changed ? `Patch R7 aplicado em ${changed} arquivo(s).` : 'Patch R7 já estava aplicado; nenhuma alteração necessária.');
console.log(`whatsapp-web.js ${pkg.version} - compatibilidade _serialized/$1 habilitada.`);
