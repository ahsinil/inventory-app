const fs = require('fs');
const path = require('path');

const replacements = {
  '#c86d6d': '#bd3c3c',
  '#c04f4f': '#bd3c3c',
  '#dd6666': '#d14848',
  '#b65e5e': '#9c2f2f',
  '#c16363': '#bd3c3c',
  '#c15f5f': '#bd3c3c',
  '#d16b6b': '#bd3c3c',
  '#d16969': '#bd3c3c',
  '#ca6868': '#bd3c3c',
  '#ce6969': '#bd3c3c',
  '#d06b6b': '#bd3c3c',
  '#c86868': '#bd3c3c',
  '#cb6868': '#bd3c3c',
  '#c76666': '#bd3c3c',
  '#c96868': '#bd3c3c',
  '#f29191': '#de6868',
  'rgba(200, 109, 109': 'rgba(189, 60, 60'
};

function walk(dir) {
  let results = [];
  const list = fs.readdirSync(dir);
  list.forEach(function(file) {
    file = path.join(dir, file);
    const stat = fs.statSync(file);
    if (stat && stat.isDirectory()) {
      results = results.concat(walk(file));
    } else if (file.endsWith('.vue') || file.endsWith('.css')) {
      results.push(file);
    }
  });
  return results;
}

const files = walk('resources');

for (const file of files) {
  let content = fs.readFileSync(file, 'utf8');
  let changed = false;
  
  for (const [oldC, newC] of Object.entries(replacements)) {
    if (content.includes(oldC) || content.includes(oldC.toUpperCase())) {
      content = content.split(oldC).join(newC);
      content = content.split(oldC.toUpperCase()).join(newC);
      changed = true;
    }
  }
  
  if (changed) {
    fs.writeFileSync(file, content);
    console.log('Updated', file);
  }
}
console.log('Done.');
