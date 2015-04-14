


var state = function() {
  this.state = {};
  this.envoy = state.envoy || (new envoy());
};
rocket.inherits(state, rocket.EventTarget);


state.envoy;
