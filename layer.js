


var layer = function() {
  cache.apply(this, arguments);
  if (this.get_class_names_()[0] === 'layer') {
    layer.layers.push(this);
    if (this.get_previous_layer()) {
      this.state = rocket.clone(this.get_previous_layer().state);
    }
  } else {
    this.state = this.get_top_layer().state;
  }
};
rocket.inherits(layer, cache);


layer.layers = [];


layer.prototype.get_previous_layer = function() {
  for (var i = 0; layer.layers[i]; ++i) {
    if (layer.layers[i] === this) {
      return layer.layers[i - 1];
    }
  }
};


layer.prototype.get_top_layer = function() {
  return layer.layers[layer.layers.length - 1];
};


layer.prototype.get_layers = function() {
  return layer.layers;
};


layer.prototype.get_class_names_ = function() {
  return this.constructor.prototype.class_names_ = this.class_names_ = this.class_names_ || this.get_class_name_recursive_({
    'layer': layer,
    'component': component
  });
};


layer.prototype.get_class_name_recursive_ = function(parent, opt_prefix) {
  for (var i in parent) {
    if (
      (parent[i]) &&
      (parent[i].prototype) &&
      (this instanceof parent[i])
    ) {
      var name = opt_prefix ? rocket.clone(opt_prefix) : [];
      name.push(i);
      if (parent[i] === this.constructor) {
        return name;
      } else {
        if (name = this.get_class_name_recursive_(parent[i], name)) {
          return name;
        }
      }
    }
  }
};


layer.prototype.layer_previous_container_;


layer.prototype.render = function(opt_parent) {
  if (this.get_class_names_()[0] === 'layer') {
    rocket.EventTarget.removeAllEventListeners();
  }
  var container = rocket.createElement('div');
  this.decorate(container);
  if (container.innerHTML()) {
    var containers = [];
    var class_names = this.get_class_names_();
    for (var i = 0; class_names[i]; ++i) {
      containers.push(rocket.createElement('div').addClass(class_names[i]));
      if (i) {
        containers[i - 1].appendChild(containers[i]);
      }
    }
    containers[containers.length - 1].appendChild(container);
    if (
      (this.layer_previous_container_) &&
      (this.layer_previous_container_.parentNode().length)
    ) {
      this.layer_previous_container_.parentNode().replaceChild(containers[0], this.layer_previous_container_);
    } else {
      (opt_parent || rocket.$('body').innerHTML('')).appendChild(containers[0]);
    }
    this.layer_previous_container_ = containers[0];
    this.dispatchEvent('render');
  }
};


layer.prototype.decorate = function() {};


layer.prototype.render_previous = function(opt_cancel) {
  if (opt_cancel) {
    layer.layers.pop();
    layer.layers[layer.layers.length - 1].render();
  } else {
    layer.layers[layer.layers.length - 2].state = this.state;
    rocket.EventTarget.removeAllEventListeners();
    this.flush(function() {
      layer.layers.pop();
      layer.layers[layer.layers.length - 1].render();
    });
  }
};


layer.prototype.render_clear = function() {
  layer.layers = [this];
  this.render();
};
