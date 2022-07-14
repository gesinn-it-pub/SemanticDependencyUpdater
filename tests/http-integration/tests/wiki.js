const axios = require("axios");
const MWBot = require("mwbot");

module.exports = {
    edit: edit,
    htmlTextOf: htmlTextOf,
    showProperty: showProperty,
    ask: ask,
    runJobs: runJobs,
};

async function edit(page, text) {
  console.log('** edit ' + page + ': ' + text);
  const result = await bot({
    action: "edit",
    title: page,
    text: text,
  });
  await runJobs();
  return result;
}

async function htmlTextOf(page) {
  const response = await bot({
    action: "parse",
    page: page,
    prop: "text",
    formatversion: 2,
  });

  console.log(response.parse.text);
  return response.parse.text;
}

async function showProperty(page, property) {
    const response = await ask('[[' + page + ']]|?' + property);
    return response[page].printouts[property];
}

async function ask(query) {
  const response = await bot({
    action: "ask",
    query: query,
  });

  return response.query.results;
}

async function xhtmlTextOf(page) {
  console.log("browserHtmlTextOf");
  const response = await axios.get("http://localhost:8080/index.php/" + page);
  //console.log(response.data);
  return response.data;
}

async function bot(command) {
  let bot;
  return await (async function () {
    if (!bot) {
      bot = new MWBot({ apiUrl: "http://localhost:8080/api.php" });
      await bot.loginGetEditToken({
        username: "WikiSysop",
        password: "wiki4everyone",
      });
    }
    try {
      return await bot.request({
        ...command,
        token: bot.editToken,
        bot: true,
      });
    } catch (e) {
      console.log(e);
    }
  })();
}

async function runJobs() {
  console.log("** runJobs");
  await execShellCommand("php /var/www/html/maintenance/runJobs.php");
}

/**
 * Executes a shell command and return it as a Promise.
 * @param cmd {string}
 * @return {Promise<string>}
 */
function execShellCommand(cmd) {
  const exec = require("child_process").exec;
  return new Promise((resolve, reject) => {
    exec(cmd, (error, stdout, stderr) => {
      if (error) {
        console.warn(error);
      }
      resolve(stdout ? stdout : stderr);
    });
  });
}
