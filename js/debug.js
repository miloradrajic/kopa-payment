function selectColor(namespace) {
  let hash = 0;

  for (let i = 0; i < namespace.length; i++) {
      hash = ((hash << 5) - hash) + namespace.charCodeAt(i);
      hash |= 0; // Convert to 32bit integer
  }

  return createDebug.colors[Math.abs(hash) % createDebug.colors.length];
}

function createDebug(namespace) {
  function debug(...args) {
      // Your custom client-side debugging logic here.
      // You can use console.log or another method to display debug information.
      console.log(namespace, ...args);
  }

  debug.namespace = namespace;
  // debug.useColors = createDebug.useColors();
  debug.color = selectColor(namespace);

  // You can add additional properties and methods as needed.

  return debug;
}

// Functions like 'enable', 'disable', and 'enabled' may not be needed on the client-side,
// but you can implement them if required for your application.

// You can use createDebug to create different debug instances with unique namespaces.
const myDebug = createDebug('myNamespace');
myDebug('Debug message for myNamespace');