


var component = function() {
  layer.apply(this, arguments);
};
rocket.inherits(component, layer);


component.prototype.decorate = function() {};


component.prototype.addEventListener = function(/* var_args */) {
  rocket.EventTarget.prototype.addEventListener.apply(this, arguments);
  return this;
};
